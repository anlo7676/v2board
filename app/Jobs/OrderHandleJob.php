<?php

namespace App\Jobs;

use App\Models\Order;
use App\Models\User;
use App\Services\OrderService;
use App\Services\TelegramOrderService;
use App\Services\TelegramService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;

class OrderHandleJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    protected $tradeNo;

    public $tries = 3;
    // 需覆盖终态清理旧消息支付按钮的 TG 接口调用耗时（最多3次，单次超时3秒）
    public $timeout = 15;
    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct($tradeNo)
    {
        $this->onQueue('order_handle');
        $this->tradeNo = $tradeNo;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        $order = Order::where('trade_no', $this->tradeNo)
            ->first();

        if (!$order) return;

        $orderService = new OrderService($order);
        switch ($order->status) {
            case 0:
                if ($order->created_at <= (time() - 3600 * 2)) {
                    if ($orderService->cancel()) {
                        $this->notifyTelegram($order, 'canceled');
                    }
                }
                break;
            case 1:
                $orderService->open();
                $this->notifyTelegram($order, 'opened');
                break;
        }
    }

    // 订单开通/超时取消后，向已绑定 Telegram 的用户异步推送结果通知（覆盖余额支付与网页支付）
    private function notifyTelegram(Order $order, string $event)
    {
        if ((int)$order->type === 9) return; // 充值订单不推送订阅通知
        if (!(int)config('v2board.telegram_bot_enable', 0)) return;
        $user = User::find($order->user_id);
        if (!$user || !$user->telegram_id) return;
        // 订单已到终态，移除历史消息上残留的支付/取消按钮并追加终态状态行，避免误点
        try {
            $statusNote = $event === 'opened' ? '✅ 该订单已支付开通' : '⏰ 该订单已超时取消';
            TelegramOrderService::clearPayMessages($order->trade_no, $statusNote);
        } catch (\Exception $e) {
            // 清理失败不影响通知主流程
        }
        // 通知去重：并发/重试下同一订单同一事件只推送一次
        if (!Cache::add('TG_ORDER_NOTIFY_' . $order->trade_no . '_' . $event, 1, 86400)) return;
        $text = $event === 'opened'
            ? TelegramOrderService::buildOpenedNotification($order, $user)
            : TelegramOrderService::buildCanceledNotification($order);
        // 开通成功通知附下一步操作按钮，用户无需手打命令
        $markup = null;
        if ($event === 'opened') {
            $markup = ['inline_keyboard' => [
                [['text' => '获取订阅链接', 'callback_data' => 'subscribe'], ['text' => '查看流量', 'callback_data' => 'traffic']]
            ]];
        }
        try {
            // 纯文本发送，避免套餐名含 markdown 元字符导致通知解析失败
            SendTelegramJob::dispatch($user->telegram_id, $text, '', $markup);
        } catch (\Exception $e) {
            // 通知入队失败不影响订单开通/取消主流程
        }
    }

    // 重试耗尽仍开通失败时，向用户与管理员告警，避免“已付款却无任何反馈”
    public function failed(\Throwable $e)
    {
        $order = Order::where('trade_no', $this->tradeNo)->first();
        if (!$order || (int)$order->type === 9) return;
        if (!(int)config('v2board.telegram_bot_enable', 0)) return;
        $user = User::find($order->user_id);
        if ($user && $user->telegram_id
            && Cache::add('TG_ORDER_NOTIFY_' . $order->trade_no . '_failed', 1, 86400)) {
            try {
                SendTelegramJob::dispatch(
                    $user->telegram_id,
                    "⚠️ 订单处理异常\n———————————————\n订单号：{$order->trade_no}\n订单开通遇到问题，请联系客服处理。",
                    ''
                );
            } catch (\Exception $ex) {
            }
        }
        try {
            // 剪除异常文本中的 markdown 元字符，避免告警自身因解析失败而发不出
            $errMsg = str_replace(['`', '*', '[', ']'], '', $e->getMessage());
            (new TelegramService())->sendMessageWithAdmin(
                "订单开通失败告警\n订单号：{$this->tradeNo}\n错误：" . $errMsg,
                true
            );
        } catch (\Exception $ex) {
        }
    }
}
