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
    }
}
