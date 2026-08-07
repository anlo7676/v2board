<?php

namespace App\Http\Controllers\V1\Guest;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\TelegramService;
use App\Services\TelegramSessionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\HttpException;

class TelegramController extends Controller
{
    protected $msg;
    protected $commands = [];
    protected $telegramService;

    public function __construct(Request $request)
    {
        if ($request->input('access_token') !== md5(config('v2board.telegram_bot_token'))) {
            abort(401);
        }

        $this->telegramService = new TelegramService();
    }

    public function webhook(Request $request)
    {
        $data = $request->input();
        // 同一 update 去重：webhook 响应慢或非 2xx 时 Telegram 会重投同一 update，
        // 不去重会重复处理（如重复发送验证码邮件、重复下单）。Cache::add 原子，可防并发重投竞态
        if (isset($data['update_id']) && !Cache::add('TG_UPDATE_' . $data['update_id'], 1, 3600)) {
            return;
        }
        $this->formatMessage($data);
        $this->formatCallbackQuery($data);
        $this->formatChatJoinRequest($data);
        $this->handle();
    }

    public function handle()
    {
        if (!$this->msg) return;
        // 机器人总开关关闭时不处理任何命令/会话/回调
        if (!(int)config('v2board.telegram_bot_enable', 0)) return;
        $msg = $this->msg;

        // 惰性同步已绑定用户的会话菜单：命令集/菜单版本变化后（含本次升级前已绑定的历史用户），
        // 用户下次私聊交互即自动补齐 member 菜单，无需等待重新绑定或 /start
        if (isset($msg->is_private) && $msg->is_private && isset($msg->chat_id)) {
            $this->syncChatMenu($msg->chat_id);
        }

        if (isset($msg->command)) {
            $commandName = explode('@', $msg->command);

            // To reduce request, only commands contains @ will get the bot name
            if (count($commandName) == 2) {
                $botName = $this->getBotName();
                if ($commandName[1] === $botName){
                    $msg->command = $commandName[0];
                }
            }
        }

        try {
            // 内联键盘回调分发
            if ($msg->message_type === 'callback_query') {
                // 同一回调去重，避免重复点击/重投触发重复处理（TTL 需覆盖 Telegram 重投窗口）
                if (!Cache::add('TG_CALLBACK_' . $msg->callback_query_id, 1, 86400)) {
                    $this->telegramService->answerCallbackQuery($msg->callback_query_id);
                    return;
                }
                foreach ($this->getCommandInstances() as $instance) {
                    if (!isset($instance->callback)) continue;
                    if ($msg->callback_data !== $instance->callback
                        && strpos($msg->callback_data, $instance->callback . ':') !== 0) continue;
                    // 匹配到处理器后立即应答，结束按钮转圈，避免用户等待中重复点击
                    $this->telegramService->answerCallbackQuery($msg->callback_query_id);
                    $instance->handle($msg);
                    return;
                }
                $this->telegramService->answerCallbackQuery($msg->callback_query_id);
                return;
            }

            // 多步会话优先分发（仅私聊，含以回复方式作答）
            if (($msg->message_type === 'message' || $msg->message_type === 'reply_message') && $msg->is_private) {
                $sessionService = new TelegramSessionService();
                $session = $sessionService->get($msg->chat_id);
                if ($session) {
                    if ($msg->command === '/cancel') {
                        $sessionService->forget($msg->chat_id);
                        $this->telegramService->sendMessage($msg->chat_id, '已取消当前操作');
                        return;
                    }
                    // 会话进行中收到其它已注册斜杠命令：不并入流程（避免被当作邮箱/密码并误计错误次数），提示先取消
                    if (is_string($msg->command) && strpos($msg->command, '/') === 0 && $this->isRegisteredCommand($msg->command)) {
                        $this->telegramService->sendMessage($msg->chat_id, '您有正在进行的操作，请先发送 /cancel 取消后再使用其它命令');
                        return;
                    }
                    foreach ($this->getCommandInstances() as $instance) {
                        if (!isset($instance->flow)) continue;
                        if ($instance->flow !== ($session['flow'] ?? '')) continue;
                        $instance->handle($msg);
                        return;
                    }
                    $sessionService->forget($msg->chat_id);
                } elseif ($msg->message_type !== 'reply_message'
                    && !(is_string($msg->command) && strpos($msg->command, '/') === 0)) {
                    // 无会话、非命令的普通文本：多为会话过期后迟到的验证码/密码等作答，
                    // 静默丢弃会让用户误以为机器人无响应，且密码明文会残留在聊天记录
                    $this->telegramService->sendMessage(
                        $msg->chat_id,
                        "没有正在进行的操作（操作会话约10分钟有效，可能已过期）。\n可发送 /start 或 /help 查看可用功能。"
                    );
                    return;
                }
            }

            foreach ($this->getCommandInstances() as $instance) {
                if ($msg->message_type === 'message') {
                    if (!isset($instance->command)) continue;
                    if ($msg->command !== $instance->command) continue;
                    $instance->handle($msg);
                    return;
                }
                if ($msg->message_type === 'reply_message') {
                    if (!isset($instance->regex)) continue;
                    if (!preg_match($instance->regex, $msg->reply_text, $match)) continue;
                    $instance->handle($msg, $match);
                    return;
                }
            }

            // 私聊中收到未注册的斜杠命令（如 /help 之外的拼写错误）：给出引导而非静默
            if ($msg->message_type === 'message' && $msg->is_private
                && is_string($msg->command) && strpos($msg->command, '/') === 0) {
                $this->telegramService->sendMessage($msg->chat_id, '未知命令，发送 /help 查看可用命令');
            }
        } catch (\Exception $e) {
            if ($msg->message_type === 'callback_query') {
                $this->telegramService->answerCallbackQuery($msg->callback_query_id);
            }
            $this->telegramService->sendMessage($msg->chat_id, $this->userFacingError($e));
        }
    }

    // 业务校验异常（abort 抛出的中文提示）直接透传；TG API/curl/DB 等系统异常统一兜底文案并记录日志
    private function userFacingError(\Exception $e): string
    {
        if ($e instanceof HttpException
            && is_string($e->getMessage()) && $e->getMessage() !== ''
            && strpos($e->getMessage(), '来自TG的错误') !== 0) {
            return $e->getMessage();
        }
        Log::warning('Telegram webhook handle error', [
            'message' => $e->getMessage(),
            'exception' => get_class($e)
        ]);
        return '操作失败，请稍后重试；若多次失败请联系客服';
    }

    private function getCommandInstances(): array
    {
        $instances = [];
        foreach (glob(base_path('app//Plugins//Telegram//Commands') . '/*.php') as $file) {
            $command = basename($file, '.php');
            $class = '\\App\\Plugins\\Telegram\\Commands\\' . $command;
            if (!class_exists($class)) continue;
            $instances[] = new $class();
        }
        return $instances;
    }

    private function isRegisteredCommand(string $command): bool
    {
        foreach ($this->getCommandInstances() as $instance) {
            if (isset($instance->command) && $instance->command === $command) return true;
        }
        return false;
    }

    // 惰性下发会话专属菜单：仅当本会话菜单受众/版本落后时才调用 TG 接口，避免每条消息都请求
    private function syncChatMenu($chatId)
    {
        try {
            $key = 'TG_CHAT_MENU_' . $chatId;
            $synced = Cache::get($key);
            $bound = User::where('telegram_id', $chatId)->exists();
            // 缓存值编码“受众@版本”，绑定状态或菜单版本变化都会触发重同步
            $mark = ($bound ? 'member' : 'guest') . '@' . TelegramService::MENU_VERSION;
            if ($synced === $mark) return;

            if ($bound) {
                // 已绑定：下发 member 会话菜单，仅成功后记录，失败留待下次交互重试
                if ($this->telegramService->applyChatCommands($chatId, 'member')) {
                    Cache::put($key, $mark, 30 * 24 * 3600);
                }
            } elseif ($synced === null) {
                // 从未同步过的陌生会话：全局默认菜单已是 guest，无需调用 TG 接口
                Cache::put($key, $mark, 30 * 24 * 3600);
            } else {
                // 曾下发过会话菜单、现已解绑：清除会话菜单回退全局默认，仅成功后记录
                if ($this->telegramService->resetChatCommands($chatId)) {
                    Cache::put($key, $mark, 30 * 24 * 3600);
                }
            }
        } catch (\Exception $e) {
            // 菜单同步失败不影响命令处理
        }
    }

    public function getBotName()
    {
        $response = $this->telegramService->getMe();
        return $response->result->username;
    }

    private function formatMessage(array $data)
    {
        if (!isset($data['message'])) return;
        if (!isset($data['message']['text'])) return;
        $obj = new \StdClass();
        $text = explode(' ', $data['message']['text']);
        $obj->command = $text[0];
        $obj->args = array_slice($text, 1);
        $obj->chat_id = $data['message']['chat']['id'];
        $obj->message_id = $data['message']['message_id'];
        $obj->message_type = 'message';
        $obj->text = $data['message']['text'];
        $obj->is_private = $data['message']['chat']['type'] === 'private';
        if (isset($data['message']['reply_to_message']['text'])) {
            $obj->message_type = 'reply_message';
            $obj->reply_text = $data['message']['reply_to_message']['text'];
        }
        $this->msg = $obj;
    }

    private function formatCallbackQuery(array $data)
    {
        if (!isset($data['callback_query'])) return;
        if (!isset($data['callback_query']['data'])) return;
        if (!isset($data['callback_query']['message']['chat']['id'])) return;
        $callback = $data['callback_query'];
        $obj = new \StdClass();
        $obj->message_type = 'callback_query';
        $obj->callback_data = $callback['data'];
        $obj->callback_query_id = $callback['id'];
        $obj->chat_id = $callback['message']['chat']['id'];
        $obj->message_id = $callback['message']['message_id'];
        $obj->is_private = $callback['message']['chat']['type'] === 'private';
        $obj->from_id = $callback['from']['id'] ?? null;
        $this->msg = $obj;
    }

    private function formatChatJoinRequest(array $data)
    {
        if (!isset($data['chat_join_request'])) return;
        if (!isset($data['chat_join_request']['from']['id'])) return;
        if (!isset($data['chat_join_request']['chat']['id'])) return;
        $user = \App\Models\User::where('telegram_id', $data['chat_join_request']['from']['id'])
            ->first();
        if (!$user) {
            $this->telegramService->declineChatJoinRequest(
                $data['chat_join_request']['chat']['id'],
                $data['chat_join_request']['from']['id']
            );
            return;
        }
        $userService = new \App\Services\UserService();
        if (!$userService->isAvailable($user)) {
            $this->telegramService->declineChatJoinRequest(
                $data['chat_join_request']['chat']['id'],
                $data['chat_join_request']['from']['id']
            );
            return;
        }
        $userService = new \App\Services\UserService();
        $this->telegramService->approveChatJoinRequest(
            $data['chat_join_request']['chat']['id'],
            $data['chat_join_request']['from']['id']
        );
    }
}
