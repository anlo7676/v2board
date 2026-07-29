<?php

namespace App\Plugins\Telegram\Commands;

use App\Models\Order;
use App\Models\User;
use App\Plugins\Telegram\Telegram;
use App\Services\TelegramOrderService;

/**
 * 待支付订单的支付回调处理（无命令入口，仅响应内联键盘）
 * pay:{trade_no}            展示支付方式选择
 * pay:{trade_no}:{payment}  发起支付，返回直接支付链接
 */
class Pay extends Telegram {
    public $callback = 'pay';

    public function handle($message, $match = []) {
        if ($message->message_type !== 'callback_query') return;
        if (!$message->is_private) return;
        $user = User::where('telegram_id', $message->chat_id)->first();
        if (!$user) {
            $this->telegramService->sendMessage($message->chat_id, '没有查询到您的用户信息，请先使用 /login 登录或 /bind 绑定账号');
            return;
        }

        $args = explode(':', $message->callback_data);
        $tradeNo = $args[1] ?? '';
        if ($tradeNo === '') return;

        if (isset($args[2])) {
            $this->checkout($message, $user, $tradeNo, (int)$args[2]);
            return;
        }
        $this->showPaymentSelect($message, $user, $tradeNo);
    }

    private function showPaymentSelect($message, User $user, string $tradeNo)
    {
        $order = Order::where('trade_no', $tradeNo)
            ->where('user_id', $user->id)
            ->first();
        if (!$order) {
            abort(500, '订单不存在');
        }
        // 订单已支付/已取消时编辑原消息并清除键盘，避免旧消息残留按钮点击报错
        if ((int)$order->status !== 0) {
            $statusName = TelegramOrderService::ORDER_STATUS_NAMES[$order->status] ?? '未知';
            $this->telegramService->editMessageText($message->chat_id, $message->message_id,
                "订单号：{$tradeNo}\n当前状态：{$statusName}\n该订单已无需支付。");
            // 顺带移除其它历史消息上该订单残留的按钮
            TelegramOrderService::clearPayMessages($tradeNo);
            return;
        }
        $orderService = new TelegramOrderService();
        $amount = sprintf('%.2f', $order->total_amount / 100);
        $markup = $orderService->buildPaymentSelectMarkup($tradeNo);
        if (!$markup) {
            // 无可用支付方式时回退网页支付链接
            $payUrl = TelegramOrderService::buildPayUrl($tradeNo);
            $this->telegramService->editMessageText($message->chat_id, $message->message_id,
                "订单号：{$tradeNo}\n待支付金额：{$amount} 元\n———————————————\n暂无可用支付方式，请打开以下链接完成支付：\n{$payUrl}");
            return;
        }
        $text = "订单号：{$tradeNo}\n待支付金额：{$amount} 元\n———————————————\n请选择支付方式：";
        $this->telegramService->editMessageText($message->chat_id, $message->message_id, $text, $markup);
        TelegramOrderService::rememberPayMessage($tradeNo, $message->chat_id, $message->message_id);
    }

    private function checkout($message, User $user, string $tradeNo, int $paymentId)
    {
        $orderService = new TelegramOrderService();
        $result = $orderService->checkout($user, $tradeNo, $paymentId);
        $markup = $orderService->buildCheckoutMarkup($result);
        $this->telegramService->editMessageText(
            $message->chat_id,
            $message->message_id,
            $orderService->buildCheckoutMessage($result),
            $markup
        );
        if ($markup) {
            TelegramOrderService::rememberPayMessage($tradeNo, $message->chat_id, $message->message_id);
        }
    }
}
