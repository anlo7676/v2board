<?php

namespace App\Plugins\Telegram\Commands;

use App\Models\User;
use App\Plugins\Telegram\Telegram;
use App\Utils\Helper;

class Subscribe extends Telegram {
    public $command = '/subscribe';
    public $description = '获取/重置订阅链接';
    public $sort = 5;
    public $menuScope = 'member';
    public $callback = 'subscribe';

    public function handle($message, $match = []) {
        if (!$message->is_private) return;
        $user = User::where('telegram_id', $message->chat_id)->first();
        if (!$user) {
            $this->telegramService->sendMessage($message->chat_id, '没有查询到您的用户信息，请先使用 /login 登录或 /bind 绑定账号');
            return;
        }

        // 重置订阅链接：重新生成 uuid 与 token，使旧链接立即失效
        if ($message->message_type === 'callback_query' && $message->callback_data === 'subscribe:reset') {
            $user->uuid = Helper::guid(true);
            $user->token = Helper::guid();
            if (!$user->save()) {
                abort(500, '重置失败');
            }
            $this->reply($message, $user, '订阅链接已重置，旧链接立即失效');
            return;
        }

        // 获取当前订阅链接
        $this->reply($message, $user, '您的订阅链接');
    }

    private function reply($message, User $user, string $prefix)
    {
        // 使用纯文本发送：submethod=1/2 时链接含 base64 的下划线，markdown 转义会破坏链接
        $subscribeUrl = Helper::getSubscribeUrl($user->token);
        $text = "{$prefix}\n———————————————\n{$subscribeUrl}\n\n点击下方按钮可重置链接（重置后旧链接立即失效）";
        $markup = [
            'inline_keyboard' => [
                [['text' => '重置订阅链接', 'callback_data' => 'subscribe:reset']]
            ]
        ];
        if ($message->message_type === 'callback_query') {
            $this->telegramService->editMessageText($message->chat_id, $message->message_id, $text, $markup);
        } else {
            $this->telegramService->sendMessage($message->chat_id, $text, '', $markup);
        }
    }
}
