<?php

namespace App\Plugins\Telegram\Commands;

use App\Models\Order;
use App\Models\User;
use App\Plugins\Telegram\Telegram;
use App\Services\TelegramOrderService;

class Orders extends Telegram {
    public $command = '/orders';
    public $description = '查询我的订单';
    public $sort = 9;
    public $menuScope = 'member';
    public $callback = 'orders';

    public function handle($message, $match = []) {
        if (!$message->is_private) return;
        $user = User::where('telegram_id', $message->chat_id)->first();
        if (!$user) {
            $this->telegramService->sendMessage($message->chat_id, '没有查询到您的用户信息，请先使用 /login 登录或 /bind 绑定账号');
            return;
        }

        $orders = Order::where('user_id', $user->id)
            ->orderBy('created_at', 'DESC')
            ->limit(5)
            ->get();

        if ($orders->isEmpty()) {
            $this->telegramService->sendMessage($message->chat_id, '您还没有任何订单，可发送 /buy 购买订阅');
            return;
        }

        $lines = ['我的订单（最近5笔）', '———————————————'];
        $pendingTradeNo = null;
        foreach ($orders as $order) {
            $statusName = TelegramOrderService::ORDER_STATUS_NAMES[$order->status] ?? '未知';
            $periodName = TelegramOrderService::PERIOD_NAMES[$order->period] ?? $order->period;
            $amount = sprintf('%.2f', ((int)$order->total_amount + (int)$order->balance_amount) / 100);
            $lines[] = "订单号：{$order->trade_no}";
            $lines[] = "类型：{$periodName}  金额：{$amount}元  状态：{$statusName}";
            $lines[] = '时间：' . date('Y-m-d H:i', $order->created_at);
            $lines[] = '———————————————';
            // 记录最近一笔待支付订单，供再次支付
            if ((int)$order->status === 0 && !$pendingTradeNo) {
                $pendingTradeNo = $order->trade_no;
            }
        }

        $markup = null;
        if ($pendingTradeNo) {
            // 在机器人内选择支付方式直接发起支付，不再跳转网页订单页
            $lines[] = '您有待支付订单，可点击下方按钮选择支付方式完成支付';
            $markup = [
                'inline_keyboard' => [
                    [['text' => '去支付', 'callback_data' => 'pay:' . $pendingTradeNo]]
                ]
            ];
        }

        // 纯文本发送：订单号/链接可能含下划线，markdown 转义会破坏内容
        $this->telegramService->sendMessage($message->chat_id, implode("\n", $lines), '', $markup);
    }
}
