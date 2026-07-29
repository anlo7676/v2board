<?php

namespace App\Plugins\Telegram\Commands;

use App\Models\Plan;
use App\Models\User;
use App\Plugins\Telegram\Telegram;
use App\Utils\Helper;

class Traffic extends Telegram {
    public $command = '/traffic';
    public $description = '查询套餐信息';
    public $sort = 10;
    public $menuScope = 'member';

    public function handle($message, $match = []) {
        $telegramService = $this->telegramService;
        if (!$message->is_private) return;
        $user = User::where('telegram_id', $message->chat_id)->first();
        if (!$user) {
            $telegramService->sendMessage($message->chat_id, '没有查询到您的用户信息，请先绑定账号', 'markdown');
            return;
        }
        $lines = [];
        $lines[] = "📦套餐信息";
        $lines[] = "———————————————";

        // 未订阅：无套餐
        if (!$user->plan_id) {
            $lines[] = "当前套餐：未订阅";
            $lines[] = "";
            $lines[] = "您还没有订阅套餐，可发送 /buy 购买。";
            $telegramService->sendMessage($message->chat_id, implode("\n", $lines), 'markdown');
            return;
        }

        $plan = Plan::find($user->plan_id);
        // 剥离会破坏 markdown 解析的字符（套餐名为管理员自由文本，反引号/星号/方括号会导致消息发送失败）
        $planName = str_replace(['`', '*', '[', ']'], '', $plan ? $plan->name : '未知套餐');

        // 到期与状态
        if ($user->expired_at === NULL) {
            $expireText = '长期有效';
            $statusText = '正常';
        } else {
            $expireText = date('Y-m-d H:i', $user->expired_at);
            $statusText = $user->expired_at < time() ? '已过期' : '正常';
        }
        if ($user->banned) {
            $statusText = '已停用';
        }

        $lines[] = "当前套餐：`{$planName}`";
        $lines[] = "套餐状态：`{$statusText}`";
        $lines[] = "到期时间：`{$expireText}`";

        // 流量
        $remainingBytes = $user->transfer_enable - ($user->u + $user->d);
        if ($remainingBytes < 0) $remainingBytes = 0;
        $transferEnable = Helper::trafficConvert($user->transfer_enable);
        $up = Helper::trafficConvert($user->u);
        $down = Helper::trafficConvert($user->d);
        $remaining = Helper::trafficConvert($remainingBytes);
        $lines[] = "———————————————";
        $lines[] = "计划流量：`{$transferEnable}`";
        $lines[] = "已用上行：`{$up}`";
        $lines[] = "已用下行：`{$down}`";
        $lines[] = "剩余流量：`{$remaining}`";

        // 设备与限速（仅在有限制时展示）
        if ($user->device_limit !== NULL && (int)$user->device_limit > 0) {
            $lines[] = "设备限制：`{$user->device_limit} 台`";
        }
        if ($user->speed_limit !== NULL && (int)$user->speed_limit > 0) {
            $lines[] = "速率限制：`{$user->speed_limit} Mbps`";
        }

        $lines[] = "";
        $lines[] = "可发送 /subscribe 获取订阅链接。";
        $telegramService->sendMessage($message->chat_id, implode("\n", $lines), 'markdown');
    }
}
