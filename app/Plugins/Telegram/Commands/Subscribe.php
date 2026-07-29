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

        $callbackData = $message->message_type === 'callback_query' ? $message->callback_data : '';

        // 第二步：确认后才真正重置（重新生成 uuid 与 token，旧链接立即失效，不可撤销）
        if ($callbackData === 'subscribe:reset:confirm') {
            $user->uuid = Helper::guid(true);
            $user->token = Helper::guid();
            if (!$user->save()) {
                abort(500, '重置失败');
            }
            $this->replySubscribe($message, $user, '订阅链接已重置，旧链接已失效');
            return;
        }

        // 第一步：点击“重置订阅链接”先弹出二次确认，避免误触历史消息里的旧按钮
        if ($callbackData === 'subscribe:reset') {
            $this->replyConfirm($message);
            return;
        }

        // 获取当前订阅链接（/subscribe 命令或 subscribe 回调）
        $this->replySubscribe($message, $user, '您的订阅链接');
    }

    private function replySubscribe($message, User $user, string $prefix)
    {
        // 使用纯文本发送：submethod=1/2 时链接含 base64 的下划线，markdown 转义会破坏链接
        $subscribeUrl = Helper::getSubscribeUrl($user->token);
        $text = "{$prefix}\n———————————————\n{$subscribeUrl}\n\n如需更换链接，可点击下方按钮重置（重置后旧链接立即失效）";
        $markup = [
            'inline_keyboard' => [
                [['text' => '重置订阅链接', 'callback_data' => 'subscribe:reset']]
            ]
        ];
        $this->send($message, $text, $markup);
    }

    private function replyConfirm($message)
    {
        $text = "确认要重置订阅链接吗？\n———————————————\n重置后当前链接立即失效，所有已导入的客户端都需重新导入新链接，此操作不可撤销。";
        $markup = [
            'inline_keyboard' => [
                [
                    ['text' => '确认重置', 'callback_data' => 'subscribe:reset:confirm'],
                    ['text' => '取消', 'callback_data' => 'subscribe']
                ]
            ]
        ];
        $this->send($message, $text, $markup);
    }

    private function send($message, string $text, array $markup)
    {
        if ($message->message_type === 'callback_query') {
            $this->telegramService->editMessageText($message->chat_id, $message->message_id, $text, $markup);
        } else {
            $this->telegramService->sendMessage($message->chat_id, $text, '', $markup);
        }
    }
}
