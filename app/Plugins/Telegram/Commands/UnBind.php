<?php

namespace App\Plugins\Telegram\Commands;

use App\Models\User;
use App\Plugins\Telegram\Telegram;

class UnBind extends Telegram {
    public $command = '/unbind';
    public $description = '将Telegram账号从网站解绑';
    public $sort = 12;
    public $menuScope = 'member';
    public $callback = 'unbind';

    public function handle($message, $match = []) {
        if (!$message->is_private) return;
        $user = User::where('telegram_id', $message->chat_id)->first();
        $telegramService = $this->telegramService;
        if (!$user) {
            $telegramService->sendMessage($message->chat_id, '没有查询到您的用户信息，请先绑定账号');
            return;
        }

        $callbackData = $message->message_type === 'callback_query' ? $message->callback_data : '';

        // 确认后执行解绑
        if ($callbackData === 'unbind:confirm') {
            $user->telegram_id = NULL;
            if (!$user->save()) {
                abort(500, '解绑失败');
            }
            // 解绑后清除会话专属菜单，恢复默认菜单（重新显示注册/登录）
            $telegramService->resetChatCommands($message->chat_id);
            $telegramService->editMessageText($message->chat_id, $message->message_id,
                "已解绑账号：{$user->email}\n如需重新绑定，可发送 /login 登录绑定，或在网站个人中心点击“绑定Telegram”一键完成");
            return;
        }

        // 第一步：二次确认，避免误触
        $text = "确认要解绑账号吗？\n———————————————\n当前账号：{$user->email}\n解绑后将不再收到开通/到期/流量等通知，也无法使用下单、查询等功能。";
        $markup = [
            'inline_keyboard' => [
                [
                    ['text' => '确认解绑', 'callback_data' => 'unbind:confirm'],
                    ['text' => '取消', 'callback_data' => 'unbind:cancel']
                ]
            ]
        ];
        if ($callbackData === 'unbind:cancel') {
            $telegramService->editMessageText($message->chat_id, $message->message_id, '已取消解绑操作');
            return;
        }
        if ($message->message_type === 'callback_query') {
            $telegramService->editMessageText($message->chat_id, $message->message_id, $text, $markup);
        } else {
            $telegramService->sendMessage($message->chat_id, $text, '', $markup);
        }
    }
}
