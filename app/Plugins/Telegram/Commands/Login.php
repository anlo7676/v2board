<?php

namespace App\Plugins\Telegram\Commands;

use App\Models\User;
use App\Plugins\Telegram\Telegram;
use App\Services\AuthService;
use App\Services\TelegramSessionService;
use App\Utils\CacheKey;
use App\Utils\Helper;
use Illuminate\Support\Facades\Cache;

class Login extends Telegram {
    public $command = '/login';
    public $description = '登录并获取免密登录链接';
    public $sort = 3;
    public $menuScope = 'guest';
    public $callback = 'login';
    public $flow = 'login';

    public function handle($message, $match = []) {
        if (!$message->is_private) return;
        $sessionService = new TelegramSessionService();

        if ($message->message_type === 'callback_query') {
            if ($message->callback_data !== 'login:start') return;
            $this->start($message, $sessionService);
            return;
        }

        $session = $sessionService->get($message->chat_id);
        if (!$session || ($session['flow'] ?? '') !== 'login') {
            $this->start($message, $sessionService);
            return;
        }

        switch ($session['step'] ?? '') {
            case 'await_email':
                $this->handleEmail($message, $session, $sessionService);
                break;
            case 'await_password':
                $this->handlePassword($message, $session, $sessionService);
                break;
            default:
                $sessionService->forget($message->chat_id);
                $this->start($message, $sessionService);
        }
    }

    private function start($message, TelegramSessionService $sessionService)
    {
        $user = User::where('telegram_id', $message->chat_id)->first();
        if ($user) {
            if ($user->banned) {
                abort(500, '该账户已被停止使用');
            }
            $this->sendLoginUrl($user, $message->chat_id, '登录链接已生成');
            return;
        }
        $sessionService->set($message->chat_id, [
            'flow' => 'login',
            'step' => 'await_email',
            'data' => []
        ]);
        $this->telegramService->sendMessage($message->chat_id, "登录账号\n———————————————\n请输入登录邮箱（发送 /cancel 可取消）\n登录成功后将自动绑定当前Telegram账号");
    }

    private function handleEmail($message, array $session, TelegramSessionService $sessionService)
    {
        $email = strtolower(trim($message->text));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->telegramService->sendMessage($message->chat_id, '邮箱格式有误，请重新输入（发送 /cancel 可取消）');
            return;
        }
        // 不暴露邮箱是否存在，统一在密码步骤返回“邮箱或密码有误”
        $session['data']['email'] = $email;
        $session['step'] = 'await_password';
        $sessionService->set($message->chat_id, $session);
        $this->telegramService->sendMessage($message->chat_id, '请输入密码（收到后将自动删除该条消息）');
    }

    private function handlePassword($message, array $session, TelegramSessionService $sessionService)
    {
        $password = $message->text;
        // 避免明文密码留在聊天记录
        $this->telegramService->deleteMessage($message->chat_id, $message->message_id);
        $email = $session['data']['email'] ?? '';
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $sessionService->forget($message->chat_id);
            abort(500, '登录会话已失效，请重新发送 /login');
        }

        $passwordErrorCount = 0;
        if ((int)config('v2board.password_limit_enable', 1)) {
            $passwordErrorCount = (int)Cache::get(CacheKey::get('PASSWORD_ERROR_LIMIT', $email), 0);
            if ($passwordErrorCount >= (int)config('v2board.password_limit_count', 5)) {
                $sessionService->forget($message->chat_id);
                abort(500, sprintf('密码错误次数过多，请%d分钟后再试', config('v2board.password_limit_expire', 60)));
            }
        }

        $user = User::where('email', $email)->first();
        if (!$user || !Helper::multiPasswordVerify(
            $user->password_algo,
            $user->password_salt,
            $password,
            $user->password)
        ) {
            if ((int)config('v2board.password_limit_enable', 1)) {
                Cache::put(
                    CacheKey::get('PASSWORD_ERROR_LIMIT', $email),
                    $passwordErrorCount + 1,
                    60 * (int)config('v2board.password_limit_expire', 60)
                );
            }
            $this->telegramService->sendMessage($message->chat_id, '邮箱或密码有误，请重新输入密码（发送 /cancel 可取消）');
            return;
        }

        if ($user->banned) {
            $sessionService->forget($message->chat_id);
            abort(500, '该账户已被停止使用');
        }

        $this->bindTelegram($user, $message->chat_id);
        $sessionService->forget($message->chat_id);
        $this->sendLoginUrl($user, $message->chat_id, '登录成功，已自动绑定当前Telegram账号');
    }

    private function sendLoginUrl(User $user, $chatId, string $prefix)
    {
        $loginUrl = AuthService::generateTempLoginUrl($user->id, 'dashboard', $this->botLinkBaseUrl());
        $text = "{$prefix}\n———————————————\n一次性免密登录链接（60秒内有效）：\n{$loginUrl}";
        $this->telegramService->sendMessage($chatId, $text);
    }
}
