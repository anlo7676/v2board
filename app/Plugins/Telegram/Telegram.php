<?php

namespace App\Plugins\Telegram;

use App\Models\User;
use App\Services\TelegramService;
use App\Utils\Helper;
use Illuminate\Support\Facades\Cache;

abstract class Telegram {
    abstract protected function handle($message, $match);
    public $telegramService;

    public function __construct()
    {
        $this->telegramService = new TelegramService();
    }

    protected function bindTelegram(User $user, int $chatId): void
    {
        if ($user->telegram_id && $user->telegram_id != $chatId) {
            abort(500, '该账号已经绑定了其他Telegram账号');
        }
        $user->telegram_id = $chatId;
        if (!$user->save()) {
            abort(500, '绑定失败');
        }
        // 绑定成功后为该会话切换到“已绑定”菜单（隐藏注册/登录）
        $this->telegramService->applyChatCommands($chatId, 'member');
    }

    // 从用户输入中解析订阅 token：支持完整订阅链接、裸 token，并按站点订阅方式（普通/一次性/时间戳哈希）还原为真实 token
    protected function resolveSubscribeToken(string $input): string
    {
        $input = trim($input);
        $token = '';
        // 完整链接：取 query 中的 token 参数；裸 token：直接使用
        if (preg_match('#^https?://#i', $input)) {
            $parts = parse_url($input);
            if (!$parts || empty($parts['query'])) {
                abort(500, '订阅地址无效，请确认已完整复制订阅链接');
            }
            parse_str($parts['query'], $query);
            $token = $query['token'] ?? '';
        } else {
            $token = $input;
        }
        if ($token === '') {
            abort(500, '订阅地址无效，请确认已完整复制订阅链接');
        }

        $submethod = (int)config('v2board.show_subscribe_method', 0);
        switch ($submethod) {
            case 1:
                if (!Cache::has("otpn_{$token}")) {
                    abort(500, '订阅链接已失效，请到网站重新获取后再试');
                }
                $token = Cache::get("otpn_{$token}");
                break;
            case 2:
                $usertoken = Cache::get("totp_{$token}");
                if (!$usertoken) {
                    $timestep = (int)config('v2board.show_subscribe_expire', 5) * 60;
                    $counter = floor(time() / $timestep);
                    $counterBytes = pack('N*', 0) . pack('N*', $counter);
                    $idhash = Helper::base64DecodeUrlSafe($token);
                    $parts = explode(':', $idhash, 2);
                    [$userid, $clienthash] = $parts;
                    if (!$userid || !$clienthash) {
                        abort(500, '订阅链接已失效，请到网站重新获取后再试');
                    }
                    $user = User::where('id', $userid)->select('token')->first();
                    if (!$user) {
                        abort(500, '订阅链接已失效，请到网站重新获取后再试');
                    }
                    $usertoken = $user->token;
                    $hash = hash_hmac('sha1', $counterBytes, $usertoken, false);
                    if ($clienthash !== $hash) {
                        abort(500, '订阅链接已失效，请到网站重新获取后再试');
                    }
                    Cache::put("totp_{$token}", $usertoken, $timestep);
                }
                $token = $usertoken;
                break;
            default:
                break;
        }
        return $token;
    }
}
