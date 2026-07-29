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

        if ($message->message_type === 'callback_query') {
            $args = explode(':', $message->callback_data);
            // orders:cancel:{trade_no} 二次确认；orders:cancel:{trade_no}:confirm 执行取消
            if (($args[1] ?? '') === 'cancel' && isset($args[2])) {
                if (($args[3] ?? '') === 'confirm') {
                    $this->cancelOrder($message, $user, $args[2]);
                } else {
                    $this->confirmCancel($message, $user, $args[2]);
                }
                return;
            }
        }

        $this->showOrders($message, $user);
    }

    private function showOrders($message, User $user)
    {
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
            // 在机器人内选择支付方式直接发起支付，不再跳转网页订单页；待支付订单可取消
            $lines[] = '您有待支付订单，可点击下方按钮选择支付方式完成支付，或取消订单';
            $markup = [
                'inline_keyboard' => [
                    [
                        ['text' => '去支付', 'callback_data' => 'pay:' . $pendingTradeNo],
                        ['text' => '取消订单', 'callback_data' => 'orders:cancel:' . $pendingTradeNo]
                    ]
                ]
            ];
        }

        // 纯文本发送：订单号/链接可能含下划线，markdown 转义会破坏内容
        $response = $this->telegramService->sendMessage($message->chat_id, implode("\n", $lines), '', $markup);
        // 记录带支付/取消按钮的消息，供订单终态时移除按钮
        if ($markup && $pendingTradeNo && isset($response->result->message_id)) {
            TelegramOrderService::rememberPayMessage($pendingTradeNo, $message->chat_id, $response->result->message_id);
        }
    }

    // 取消前二次确认，避免误触；编辑原消息展示确认按钮
    private function confirmCancel($message, User $user, string $tradeNo)
    {
        $order = Order::where('trade_no', $tradeNo)
            ->where('user_id', $user->id)
            ->first();
        if (!$order) {
            abort(500, '订单不存在');
        }
        if ((int)$order->status !== 0) {
            $statusName = TelegramOrderService::ORDER_STATUS_NAMES[$order->status] ?? '未知';
            $this->telegramService->editMessageText($message->chat_id, $message->message_id,
                "订单号：{$tradeNo}\n当前状态：{$statusName}\n该订单已无法取消。");
            return;
        }
        $amount = sprintf('%.2f', $order->total_amount / 100);
        $text = "确认取消订单？\n———————————————\n订单号：{$tradeNo}\n待支付金额：{$amount} 元";
        if ($order->balance_amount) {
            $balanceAmount = sprintf('%.2f', $order->balance_amount / 100);
            $text .= "\n取消后余额抵扣的 {$balanceAmount} 元将退回账户余额";
        }
        $markup = [
            'inline_keyboard' => [
                [
                    ['text' => '确认取消', 'callback_data' => "orders:cancel:{$tradeNo}:confirm"],
                    ['text' => '« 返回', 'callback_data' => 'pay:' . $tradeNo]
                ]
            ]
        ];
        $this->telegramService->editMessageText($message->chat_id, $message->message_id, $text, $markup);
    }

    private function cancelOrder($message, User $user, string $tradeNo)
    {
        $orderService = new TelegramOrderService();
        $order = $orderService->cancelOrder($user, $tradeNo);
        $text = "订单已取消\n———————————————\n订单号：{$tradeNo}";
        if ($order->balance_amount) {
            $balanceAmount = sprintf('%.2f', $order->balance_amount / 100);
            $text .= "\n余额抵扣的 {$balanceAmount} 元已退回账户余额";
        }
        $text .= "\n如需购买请发送 /buy。";
        $this->telegramService->editMessageText($message->chat_id, $message->message_id, $text);
    }
}
