<?php

namespace App\Plugins\Telegram\Commands;

use App\Models\User;
use App\Plugins\Telegram\Telegram;

class UnBind extends Telegram {
    public $command = '/unbind';
    public $description = '将Telegram账号从网站解绑';
    public $sort = 11;
    public $menuScope = 'member';

    public function handle($message, $match = []) {
        if (!$message->is_private) return;
        $user = User::where('telegram_id', $message->chat_id)->first();
        $telegramService = $this->telegramService;
        if (!$user) {
            $telegramService->sendMessage($message->chat_id, '没有查询到您的用户信息，请先绑定账号', 'markdown');
            return;
        }
        $user->telegram_id = NULL;
        if (!$user->save()) {
            abort(500, '解绑失败');
        }
        // 解绑后清除会话专属菜单，恢复默认菜单（重新显示注册/登录）
        $telegramService->resetChatCommands($message->chat_id);
        $telegramService->sendMessage($message->chat_id, '解绑成功', 'markdown');
    }
}
