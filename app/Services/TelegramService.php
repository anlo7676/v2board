<?php
namespace App\Services;

use App\Jobs\SendTelegramJob;
use App\Models\User;
use \Curl\Curl;
use Illuminate\Mail\Markdown;

class TelegramService {
    // 命令菜单版本：命令增删/排序/可见范围等菜单结构变更时递增，已绑定用户下次私聊交互会自动重新下发会话菜单
    const MENU_VERSION = '4';
    protected $api;

    public function __construct($token = '')
    {
        $this->api = 'https://api.telegram.org/bot' . config('v2board.telegram_bot_token', $token) . '/';
    }

    public function sendMessage(int $chatId, string $text, string $parseMode = '', $replyMarkup = null)
    {
        if ($parseMode === 'markdown') {
            $text = str_replace('_', '\_', $text);
        }
        $params = [
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => $parseMode
        ];
        if ($replyMarkup) {
            $params['reply_markup'] = json_encode($replyMarkup);
        }
        $this->request('sendMessage', $params, (bool)$replyMarkup);
    }

    public function editMessageText(int $chatId, int $messageId, string $text, $replyMarkup = null, string $parseMode = '')
    {
        if ($parseMode === 'markdown') {
            $text = str_replace('_', '\_', $text);
        }
        $params = [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'text' => $text,
            'parse_mode' => $parseMode
        ];
        if ($replyMarkup) {
            $params['reply_markup'] = json_encode($replyMarkup);
        }
        try {
            $this->request('editMessageText', $params, (bool)$replyMarkup);
        } catch (\Exception $e) {
            // 内容未变化时 TG 会报 message is not modified，可安全忽略
            if (strpos($e->getMessage(), 'not modified') === false) {
                throw $e;
            }
        }
    }

    public function answerCallbackQuery(string $callbackQueryId, string $text = '', bool $showAlert = false)
    {
        try {
            $this->request('answerCallbackQuery', [
                'callback_query_id' => $callbackQueryId,
                'text' => $text,
                'show_alert' => $showAlert ? 'true' : 'false'
            ]);
        } catch (\Exception $e) {
            // 应答失败（如回调已过期）不影响主流程
        }
    }

    public function deleteMessage(int $chatId, int $messageId)
    {
        try {
            $this->request('deleteMessage', [
                'chat_id' => $chatId,
                'message_id' => $messageId
            ]);
        } catch (\Exception $e) {
            // 删除失败不影响主流程
        }
    }

    public function approveChatJoinRequest(int $chatId, int $userId)
    {
        $this->request('approveChatJoinRequest', [
            'chat_id' => $chatId,
            'user_id' => $userId
        ]);
    }

    public function declineChatJoinRequest(int $chatId, int $userId)
    {
        $this->request('declineChatJoinRequest', [
            'chat_id' => $chatId,
            'user_id' => $userId
        ]);
    }

    public function getMe()
    {
        return $this->request('getMe');
    }

    public function setWebhook(string $url)
    {
        $dir = base_path('app/Plugins/Telegram/Commands');
        // 私聊显示完整菜单
        $this->setMyCommands($this->discoverCommands($dir, 'guest', 'private'), ['type' => 'all_private_chats']);
        // 群组仅显示群内可用命令，隐藏所有需私聊的命令
        $this->setMyCommands($this->discoverCommands($dir, 'guest', 'group'), ['type' => 'all_group_chats']);
        // 清除历史默认作用域菜单，避免旧命令残留在群组等场景
        $this->deleteMyCommands();
        return $this->request('setWebhook', [
            'url' => $url
        ]);
    }

    public function discoverCommands(string $directory, string $audience = 'guest', string $chatType = 'private'): array
    {
        $commands = [];

        foreach (glob($directory . '/*.php') as $file) {
            $className = 'App\\Plugins\\Telegram\\Commands\\' . basename($file, '.php');

            if (!class_exists($className)) {
                require_once $file;
            }

            if (!class_exists($className)) {
                continue;
            }

            try {
                $ref = new \ReflectionClass($className);
                // 一次性读取默认属性值，兼容 PHP7.3，避免多次实例化与“声明未赋值”导致的 null 陷阱
                $defaults = $ref->getDefaultProperties();

                if (!isset($defaults['command']) || !isset($defaults['description'])) {
                    continue;
                }

                $command = $defaults['command'];
                $description = $defaults['description'];
                // 菜单可见范围：all(默认均可见) / guest(仅未绑定) / member(仅已绑定) / none(从不入菜单)
                $menuScope = isset($defaults['menuScope']) ? $defaults['menuScope'] : 'all';
                // 是否在群组菜单显示（默认否，即仅私聊可用）
                $groupVisible = !empty($defaults['groupVisible']);

                if ($chatType === 'group') {
                    // 群组菜单：仅保留群内可用命令
                    if (!$groupVisible) continue;
                } else {
                    // 私聊菜单：按绑定状态分层
                    if ($menuScope === 'none') continue;
                    if ($menuScope !== 'all' && $menuScope !== $audience) continue;
                }

                // 未声明或非数值 sort 的命令排在最后
                $sort = (isset($defaults['sort']) && is_numeric($defaults['sort'])) ? (int)$defaults['sort'] : 999;

                $commands[] = [
                    'command' => $command,
                    'description' => $description,
                    'sort' => $sort,
                ];
            } catch (\ReflectionException $e) {
                continue;
            }
        }
        // 按 sort 升序排列菜单，未声明 sort 的命令排在最后
        usort($commands, function ($a, $b) {
            return $a['sort'] <=> $b['sort'];
        });
        // Telegram setMyCommands 仅接受 command/description，剔除仅用于排序的 sort 字段
        return array_map(function ($item) {
            return [
                'command' => $item['command'],
                'description' => $item['description'],
            ];
        }, $commands);
    }

    public function setMyCommands(array $commands, array $scope = null)
    {
        $params = [
            'commands' => json_encode($commands),
        ];
        if ($scope) {
            $params['scope'] = json_encode($scope);
        }
        $this->request('setMyCommands', $params, false, 5);
    }

    public function deleteMyCommands(array $scope = null)
    {
        $params = [];
        if ($scope) {
            $params['scope'] = json_encode($scope);
        }
        $this->request('deleteMyCommands', $params, false, 5);
    }

    // 为指定会话设置专属命令菜单（已绑定用户隐藏注册/登录），菜单接口异常不影响主流程
    public function applyChatCommands(int $chatId, string $audience): bool
    {
        try {
            $commands = $this->discoverCommands(base_path('app/Plugins/Telegram/Commands'), $audience);
            $this->setMyCommands($commands, ['type' => 'chat', 'chat_id' => $chatId]);
            return true;
        } catch (\Exception $e) {
            // 菜单同步失败不阻断绑定/解绑等主流程
            return false;
        }
    }

    // 清除会话专属菜单，回退到全局默认菜单（重新显示注册/登录）
    public function resetChatCommands(int $chatId): bool
    {
        try {
            $this->deleteMyCommands(['type' => 'chat', 'chat_id' => $chatId]);
            return true;
        } catch (\Exception $e) {
            // 忽略失败
            return false;
        }
    }

    private function request(string $method, array $params = [], bool $post = false, int $timeout = 0)
    {
        $curl = new Curl();
        if ($timeout > 0) {
            // 为非关键的菜单类接口设置超时，避免 TG API 缓慢时拖长 webhook 响应导致重投
            $curl->setOpt(CURLOPT_CONNECTTIMEOUT, $timeout);
            $curl->setOpt(CURLOPT_TIMEOUT, $timeout);
        }
        if ($post) {
            $curl->post($this->api . $method, $params);
        } else {
            $curl->get($this->api . $method . '?' . http_build_query($params));
        }
        $response = $curl->response;
        $curl->close();
        if (!isset($response->ok)) abort(500, '请求失败');
        if (!$response->ok) {
            abort(500, '来自TG的错误：' . $response->description);
        }
        return $response;
    }

    public function sendMessageWithAdmin($message, $isStaff = false)
    {
        if (!config('v2board.telegram_bot_enable', 0)) return;
        $users = User::where(function ($query) use ($isStaff) {
            $query->where('is_admin', 1);
            if ($isStaff) {
                $query->orWhere('is_staff', 1);
            }
        })
            ->where('telegram_id', '!=', NULL)
            ->get();
        foreach ($users as $user) {
            SendTelegramJob::dispatch($user->telegram_id, $message);
        }
    }
}
