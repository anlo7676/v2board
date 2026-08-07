<?php

namespace App\Plugins\Telegram\Commands;

use App\Models\User;
use App\Plugins\Telegram\Telegram;

class Bind extends Telegram {
    public $command = '/bind';
    public $description = '将Telegram账号绑定到网站';
    public $sort = 4;
    public $menuScope = 'none';

    public function handle($message, $match = []) {
        if (!$message->is_private) return;
        if (!isset($message->args[0]) || trim($message->args[0]) === '') {
            abort(500, "请携带订阅链接发送，例如：\n/bind https://example.com/api/v1/client/subscribe?token=xxxx\n也可在网站个人中心点击“绑定Telegram”一键完成绑定");
        }
        $token = $this->resolveSubscribeToken($message->args[0]);
        $user = User::where('token', $token)->first();
        if (!$user) {
            abort(500, '未找到该订阅对应的用户，请确认订阅链接无误');
        }
        if ($user->banned) {
            abort(500, '该账户已被停止使用');
        }
        $this->bindTelegram($user, $message->chat_id);
        $this->telegramService->sendMessage($message->chat_id, "绑定成功，当前账号：{$user->email}\n可发送 /help 查看可用功能");
    }
}
