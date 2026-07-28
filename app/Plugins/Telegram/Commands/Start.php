<?php

namespace App\Plugins\Telegram\Commands;

use App\Plugins\Telegram\Telegram;

class Start extends Telegram {
    public $command = '/start';
    public $description = '开始使用机器人';

    public function handle($message, $match = []) {
        if (!$message->is_private) return;
        $text = sprintf(
            "欢迎使用 %s 机器人\n———————————————\n请点击下方按钮操作，或使用以下命令：\n/register 注册账号\n/login 登录、获取免密登录链接\n/buy 购买订阅\n/renew 续费当前订阅\n/reset 购买流量重置包\n/traffic 查询流量\n/bind 通过订阅地址绑定账号\n/cancel 取消当前操作",
            config('v2board.app_name', 'V2Board')
        );
        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '注册账号', 'callback_data' => 'register:start'],
                    ['text' => '登录', 'callback_data' => 'login:start']
                ],
                [
                    ['text' => '购买订阅', 'callback_data' => 'buy'],
                    ['text' => '续费', 'callback_data' => 'renew'],
                    ['text' => '重置流量', 'callback_data' => 'reset']
                ]
            ]
        ];
        $this->telegramService->sendMessage($message->chat_id, $text, '', $keyboard);
    }
}
