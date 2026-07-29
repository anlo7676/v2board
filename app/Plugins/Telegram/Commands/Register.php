<?php

namespace App\Plugins\Telegram\Commands;

use App\Jobs\SendEmailJob;
use App\Models\Plan;
use App\Models\User;
use App\Plugins\Telegram\Telegram;
use App\Services\TelegramSessionService;
use App\Utils\CacheKey;
use App\Utils\Dict;
use App\Utils\Helper;
use Illuminate\Support\Facades\Cache;

class Register extends Telegram {
    public $command = '/register';
    public $description = '注册新账号';
    public $sort = 2;
    public $menuScope = 'guest';
    public $callback = 'register';
    public $flow = 'register';

    public function handle($message, $match = []) {
        if (!$message->is_private) return;
        $sessionService = new TelegramSessionService();

        if ($message->message_type === 'callback_query') {
            if ($message->callback_data !== 'register:start') return;
            $this->start($message, $sessionService);
            return;
        }

        $session = $sessionService->get($message->chat_id);
        if (!$session || ($session['flow'] ?? '') !== 'register') {
            $this->start($message, $sessionService);
            return;
        }

        switch ($session['step'] ?? '') {
            case 'await_email':
                $this->handleEmail($message, $session, $sessionService);
                break;
            case 'await_email_code':
                $this->handleEmailCode($message, $session, $sessionService);
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
        if ((int)config('v2board.stop_register', 0)) {
            abort(500, '本站已关闭注册');
        }
        if ((int)config('v2board.invite_force', 0)) {
            abort(500, '本站开启了强制邀请码注册，请前往网站使用邀请码注册');
        }
        if ($this->reachRegisterLimit($message->chat_id)) {
            abort(500, sprintf('注册过于频繁，请%d分钟后再试', config('v2board.register_limit_expire', 60)));
        }
        $user = User::where('telegram_id', $message->chat_id)->first();
        if ($user) {
            abort(500, '当前Telegram已绑定账号，可直接使用 /login 获取登录链接');
        }
        $sessionService->set($message->chat_id, [
            'flow' => 'register',
            'step' => 'await_email',
            'data' => []
        ]);
        $this->telegramService->sendMessage($message->chat_id, "开始注册账号\n———————————————\n请输入您的邮箱（发送 /cancel 可取消）");
    }

    private function reachRegisterLimit($chatId): bool
    {
        if (!(int)config('v2board.register_limit_by_ip_enable', 0)) {
            return false;
        }
        $count = (int)(Cache::get(CacheKey::get('REGISTER_IP_RATE_LIMIT', 'tg_' . $chatId)) ?? 0);
        return $count >= (int)config('v2board.register_limit_count', 3);
    }

    private function incrRegisterLimit($chatId): void
    {
        if (!(int)config('v2board.register_limit_by_ip_enable', 0)) {
            return;
        }
        $cacheKey = CacheKey::get('REGISTER_IP_RATE_LIMIT', 'tg_' . $chatId);
        $count = (int)(Cache::get($cacheKey) ?? 0);
        Cache::put($cacheKey, $count + 1, (int)config('v2board.register_limit_expire', 60) * 60);
    }

    private function handleEmail($message, array $session, TelegramSessionService $sessionService)
    {
        $email = strtolower(trim($message->text));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->telegramService->sendMessage($message->chat_id, '邮箱格式有误，请重新输入（发送 /cancel 可取消）');
            return;
        }
        if ((int)config('v2board.email_whitelist_enable', 0)) {
            if (!Helper::emailSuffixVerify($email, config('v2board.email_whitelist_suffix', Dict::EMAIL_WHITELIST_SUFFIX_DEFAULT))) {
                $this->telegramService->sendMessage($message->chat_id, '邮箱后缀不处于白名单中，请重新输入（发送 /cancel 可取消）');
                return;
            }
        }
        if ((int)config('v2board.email_gmail_limit_enable', 0)) {
            $prefix = explode('@', $email)[0];
            if (strpos($prefix, '.') !== false || strpos($prefix, '+') !== false) {
                $this->telegramService->sendMessage($message->chat_id, '不支持Gmail别名邮箱，请重新输入（发送 /cancel 可取消）');
                return;
            }
        }
        if (User::where('email', $email)->exists()) {
            $this->telegramService->sendMessage($message->chat_id, '该邮箱已被注册，请重新输入，或使用 /login 登录（发送 /cancel 可取消）');
            return;
        }
        $session['data']['email'] = $email;
        if ((int)config('v2board.email_verify', 0)) {
            $this->sendEmailVerify($email);
            $session['step'] = 'await_email_code';
            $sessionService->set($message->chat_id, $session);
            $this->telegramService->sendMessage($message->chat_id, '验证码已发送至您的邮箱，请输入6位数字验证码（发送 /cancel 可取消）');
            return;
        }
        $session['step'] = 'await_password';
        $sessionService->set($message->chat_id, $session);
        $this->telegramService->sendMessage($message->chat_id, '请输入密码（不少于8位，收到后将自动删除该条消息）');
    }

    private function handleEmailCode($message, array $session, TelegramSessionService $sessionService)
    {
        $inputCode = trim($message->text);
        $email = $session['data']['email'];
        $cachedCode = Cache::get(CacheKey::get('EMAIL_VERIFY_CODE', $email));
        if (!preg_match('/^\d{6}$/', $inputCode)
            || $cachedCode === null || $cachedCode === ''
            || !hash_equals((string)$cachedCode, $inputCode)) {
            $this->telegramService->sendMessage($message->chat_id, '邮箱验证码有误，请重新输入（发送 /cancel 可取消）');
            return;
        }
        $session['step'] = 'await_password';
        $sessionService->set($message->chat_id, $session);
        $this->telegramService->sendMessage($message->chat_id, '验证成功，请输入密码（不少于8位，收到后将自动删除该条消息）');
    }

    private function handlePassword($message, array $session, TelegramSessionService $sessionService)
    {
        $password = $message->text;
        // 避免明文密码留在聊天记录
        $this->telegramService->deleteMessage($message->chat_id, $message->message_id);
        if (mb_strlen($password) < 8) {
            $this->telegramService->sendMessage($message->chat_id, '密码不得少于8位，请重新输入（发送 /cancel 可取消）');
            return;
        }
        if ((int)config('v2board.invite_force', 0)) {
            $sessionService->forget($message->chat_id);
            abort(500, '本站开启了强制邀请码注册，请前往网站使用邀请码注册');
        }
        if ((int)config('v2board.stop_register', 0)) {
            $sessionService->forget($message->chat_id);
            abort(500, '本站已关闭注册');
        }
        if ($this->reachRegisterLimit($message->chat_id)) {
            $sessionService->forget($message->chat_id);
            abort(500, sprintf('注册过于频繁，请%d分钟后再试', config('v2board.register_limit_expire', 60)));
        }
        $email = $session['data']['email'] ?? '';
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $sessionService->forget($message->chat_id);
            abort(500, '注册会话已失效，请重新发送 /register');
        }
        if (User::where('email', $email)->exists()) {
            $sessionService->forget($message->chat_id);
            abort(500, '该邮箱已被注册');
        }
        if (User::where('telegram_id', $message->chat_id)->exists()) {
            $sessionService->forget($message->chat_id);
            abort(500, '当前Telegram已绑定账号，可直接使用 /login 获取登录链接');
        }

        $user = new User();
        $user->email = $email;
        $user->password = password_hash($password, PASSWORD_DEFAULT);
        $user->uuid = Helper::guid(true);
        $user->token = Helper::guid();

        // try out
        if ((int)config('v2board.try_out_plan_id', 0)) {
            $plan = Plan::find(config('v2board.try_out_plan_id'));
            if ($plan) {
                $user->transfer_enable = $plan->transfer_enable * 1073741824;
                $user->device_limit = $plan->device_limit;
                $user->plan_id = $plan->id;
                $user->group_id = $plan->group_id;
                $user->expired_at = time() + (config('v2board.try_out_hour', 1) * 3600);
                $user->speed_limit = $plan->speed_limit;
            }
        }

        $user->last_login_at = time();
        if (!$user->save()) {
            abort(500, '注册失败');
        }
        if ((int)config('v2board.email_verify', 0)) {
            Cache::forget(CacheKey::get('EMAIL_VERIFY_CODE', $email));
        }
        $this->incrRegisterLimit($message->chat_id);
        $this->bindTelegram($user, $message->chat_id);
        $sessionService->forget($message->chat_id);

        $text = "注册成功，已自动绑定当前Telegram账号\n———————————————\n邮箱：{$email}\n\n可使用 /buy 购买订阅";
        $this->telegramService->sendMessage($message->chat_id, $text);
    }

    private function sendEmailVerify(string $email)
    {
        if (Cache::get(CacheKey::get('LAST_SEND_EMAIL_VERIFY_TIMESTAMP', $email))) {
            abort(500, '验证码已发送，请过一会再请求');
        }
        $code = (string)rand(100000, 999999);
        $subject = config('v2board.app_name', 'V2Board') . __('Email verification code');
        SendEmailJob::dispatch([
            'email' => $email,
            'subject' => $subject,
            'template_name' => 'verify',
            'template_value' => [
                'name' => config('v2board.app_name', 'V2Board'),
                'code' => $code,
                'url' => config('v2board.app_url')
            ]
        ]);
        Cache::put(CacheKey::get('EMAIL_VERIFY_CODE', $email), $code, 300);
        Cache::put(CacheKey::get('LAST_SEND_EMAIL_VERIFY_TIMESTAMP', $email), time(), 60);
    }
}
