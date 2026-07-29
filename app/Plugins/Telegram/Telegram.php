<?php

namespace App\Plugins\Telegram;

use App\Models\User;
use App\Services\TelegramService;

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

    // 机器人生成的链接（支付/免密登录）使用的域名：优先专用支付域名，未配置时回退到站点网址
    protected function botLinkBaseUrl(): string
    {
        return (string)(config('v2board.telegram_payment_domain') ?: config('v2board.app_url') ?: '');
    }
}
