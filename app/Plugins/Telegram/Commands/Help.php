<?php

namespace App\Plugins\Telegram\Commands;

use App\Models\User;
use App\Plugins\Telegram\Telegram;

class Help extends Telegram {
    public $command = '/help';
    public $description = '查看使用帮助';
    public $sort = 13;
    public $menuScope = 'all';

    public function handle($message, $match = []) {
        if (!$message->is_private) return;
        $user = User::where('telegram_id', $message->chat_id)->first();

        $lines = [];
        $lines[] = sprintf("%s 机器人使用帮助", config('v2board.app_name', 'V2Board'));
        $lines[] = "———————————————";
        if (!$user) {
            $lines[] = "您还未绑定账号，请先完成以下任一操作：";
            $lines[] = "/register 注册新账号";
            $lines[] = "/login 登录并绑定已有账号";
            $lines[] = "/bind 订阅链接 绑定已有账号（也可在网站个人中心点击“绑定Telegram”一键完成）";
        } else {
            $lines[] = "账号：{$user->email}";
            $lines[] = "";
            $lines[] = "可用命令：";
            $lines[] = "/buy 购买订阅";
            $lines[] = "/renew 续费当前订阅";
            $lines[] = "/reset 购买流量重置包";
            $lines[] = "/traffic 查询套餐与流量";
            $lines[] = "/subscribe 获取/重置订阅链接";
            $lines[] = "/orders 查询我的订单";
            $lines[] = "/unbind 解绑账号";
        }
        $lines[] = "/getlatesturl 获取最新站点地址";
        $lines[] = "/cancel 取消当前操作";
        $lines[] = "";
        $lines[] = "操作会话约10分钟有效，超时后需重新发起命令。";

        $this->telegramService->sendMessage($message->chat_id, implode("\n", $lines));
    }
}
