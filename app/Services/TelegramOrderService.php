<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\User;
use App\Utils\Helper;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class TelegramOrderService
{
    CONST PERIODS = [
        'month_price',
        'quarter_price',
        'half_year_price',
        'year_price',
        'two_year_price',
        'three_year_price',
        'onetime_price',
        'reset_price'
    ];

    CONST PERIOD_NAMES = [
        'month_price' => '月付',
        'quarter_price' => '季付',
        'half_year_price' => '半年付',
        'year_price' => '年付',
        'two_year_price' => '两年付',
        'three_year_price' => '三年付',
        'onetime_price' => '一次性',
        'reset_price' => '流量重置包'
    ];

    CONST ORDER_STATUS_NAMES = [
        0 => '待支付',
        1 => '开通中',
        2 => '已取消',
        3 => '已开通',
        4 => '已抵扣'
    ];

    // 需前端采集客户端 token（如 Stripe.js 卡信息）的网关，TG 内无法采集，不可选
    CONST CLIENT_TOKEN_PAYMENTS = ['StripeCredit'];

    /**
     * 创建订单并结算（镜像 User\OrderController::save + checkout 的核心逻辑）
     * 余额足额自动开通，否则返回网页支付链接
     */
    public function createAndCheckout(User $user, int $planId, string $period): array
    {
        if (!in_array($period, self::PERIODS)) {
            abort(500, '参数有误');
        }
        // 加用户级锁，避免重复点击按钮/Telegram 重投同一 update 导致并发建单重复扣款
        $lock = Cache::lock('TELEGRAM_ORDER_LOCK_' . $user->id, 10);
        if (!$lock->get()) {
            abort(500, '操作过于频繁，请稍后再试');
        }
        try {
            return $this->process($user, $planId, $period);
        } finally {
            $lock->release();
        }
    }

    private function process(User $user, int $planId, string $period): array
    {
        if ($user->banned) {
            abort(500, '您的账户已被停止使用，无法下单');
        }
        $userService = new UserService();
        if ($userService->isNotCompleteOrderByUserId($user->id)) {
            abort(500, '您有未付款或开通中的订单，请先支付或取消后再试');
        }

        $planService = new PlanService($planId);
        $plan = $planService->plan;
        if (!$plan) {
            abort(500, '该订阅不存在');
        }
        if ($user->plan_id !== $plan->id && !$planService->haveCapacity() && $period !== 'reset_price') {
            abort(500, '当前订阅已售罄');
        }
        if ($plan[$period] === NULL) {
            abort(500, '该订阅周期无法进行购买，请选择其它周期');
        }
        if ($period === 'reset_price') {
            if (!$userService->isAvailable($user) || $plan->id !== $user->plan_id) {
                abort(500, '订阅已过期或无有效订阅，无法购买流量重置包');
            }
        }
        if ((!$plan->show && !$plan->renew) || (!$plan->show && $user->plan_id !== $plan->id)) {
            if ($period !== 'reset_price') {
                abort(500, '该订阅已售罄，请选择其它订阅');
            }
        }
        if (!$plan->renew && $user->plan_id == $plan->id && $period !== 'reset_price') {
            abort(500, '该订阅无法续费，请更换其它订阅');
        }
        if (!$plan->show && $plan->renew && !$userService->isAvailable($user)) {
            abort(500, '该订阅已过期，请更换其它订阅');
        }

        DB::beginTransaction();
        try {
            $order = new Order();
            $orderService = new OrderService($order);
            $order->user_id = $user->id;
            $order->plan_id = $plan->id;
            $order->period = $period;
            $order->trade_no = Helper::generateOrderNo();
            $order->total_amount = $plan[$period];
            // 显式置为待支付，避免内存模型 status 为 NULL 导致 OrderService::paid() 的 `status !== 0` 判断误返回（不开通）
            $order->status = 0;

            $orderService->setVipDiscount($user);
            $orderService->setOrderType($user);

            if ($user->balance > 0 && $order->total_amount > 0) {
                $remainingBalance = $user->balance - $order->total_amount;
                if ($remainingBalance > 0) {
                    if (!$userService->addBalance($order->user_id, - $order->total_amount)) {
                        DB::rollBack();
                        abort(500, '余额不足');
                    }
                    $order->balance_amount = $order->total_amount;
                    $order->total_amount = 0;
                } else {
                    if (!$userService->addBalance($order->user_id, - $user->balance)) {
                        DB::rollBack();
                        abort(500, '余额不足');
                    }
                    $order->balance_amount = $user->balance;
                    $order->total_amount -= $user->balance;
                }
            }

            $orderService->setInvite($user);

            if (!$order->save()) {
                DB::rollback();
                abort(500, '订单创建失败');
            }

            DB::commit();
        } catch (\Throwable $e) {
            // 避免常驻进程（webman）下异常导致事务悬挂泄漏到后续请求
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            throw $e;
        }

        // 拒绝负金额订单，负数不应出现于任何合法路径
        if ($order->total_amount < 0) {
            abort(500, '订单金额异常，请联系客服');
        }
        // 余额全额抵扣，直接标记支付并异步开通
        if ($order->total_amount === 0) {
            if (!$orderService->paid($order->trade_no)) {
                abort(500, '订单支付失败，请到网站订单页处理');
            }
            return [
                'status' => 'opened',
                'trade_no' => $order->trade_no
            ];
        }

        return [
            'status' => 'pending',
            'trade_no' => $order->trade_no,
            'total_amount' => $order->total_amount,
            'pay_url' => self::buildPayUrl($order->trade_no)
        ];
    }

    /**
     * 选择支付方式后发起支付（镜像 User\OrderController::checkout 的核心逻辑）
     * 返回网关结果：type 1=跳转链接 0=二维码内容 -1=余额已抵扣直接开通
     */
    public function checkout(User $user, string $tradeNo, int $paymentId): array
    {
        // 与下单共用用户级锁防重复点击并发；TTL 需覆盖网关外部 HTTP 调用耗时，避免锁提前过期后重入
        $lock = Cache::lock('TELEGRAM_ORDER_LOCK_' . $user->id, 60);
        if (!$lock->get()) {
            abort(500, '操作过于频繁，请稍后再试');
        }
        try {
            $order = Order::where('trade_no', $tradeNo)
                ->where('user_id', $user->id)
                ->where('status', 0)
                ->first();
            if (!$order) {
                abort(500, '订单不存在或已支付');
            }
            // 拒绝负金额订单，负数不应出现于任何合法路径
            if ($order->total_amount < 0) {
                abort(500, '订单金额异常，请联系客服');
            }
            // 免支付订单（金额为0）直接标记支付并异步开通
            if ($order->total_amount === 0) {
                $orderService = new OrderService($order);
                if (!$orderService->paid($order->trade_no)) {
                    abort(500, '订单支付失败，请到网站订单页处理');
                }
                return ['type' => -1, 'data' => true, 'trade_no' => $tradeNo];
            }
            $payment = Payment::find($paymentId);
            if (!$payment || $payment->enable !== 1 || in_array($payment->payment, self::CLIENT_TOKEN_PAYMENTS)) {
                abort(500, '该支付方式不可用，请重新选择');
            }
            $paymentService = new PaymentService($payment->payment, $payment->id);
            $order->handling_amount = NULL;
            if ($payment->handling_fee_fixed || $payment->handling_fee_percent) {
                $order->handling_amount = round(($order->total_amount * ($payment->handling_fee_percent / 100)) + $payment->handling_fee_fixed);
            }
            $order->payment_id = $payment->id;
            if (!$order->save()) {
                abort(500, '请求失败，请稍后再试');
            }
            $payAmount = isset($order->handling_amount) ? ($order->total_amount + $order->handling_amount) : $order->total_amount;
            $result = $paymentService->pay([
                'trade_no' => $tradeNo,
                'total_amount' => $payAmount,
                'user_id' => $order->user_id,
                'stripe_token' => null,
                // 支付完成后回跳到用户访问域名的订单页，避免 webhook 域名≠站点域名时回跳到错误地址
                'return_url' => self::buildPayUrl($tradeNo)
            ]);
            return [
                'type' => $result['type'],
                'data' => $result['data'],
                'trade_no' => $tradeNo,
                'payment_name' => $payment->name,
                'pay_amount' => $payAmount
            ];
        } finally {
            $lock->release();
        }
    }

    /**
     * 取消待支付订单（镜像 User\OrderController::cancel 的核心逻辑），余额抵扣部分退回账户余额
     */
    public function cancelOrder(User $user, string $tradeNo): Order
    {
        // 用户级锁防重复点击并发取消导致余额重复退回，也避免与发起支付互相穿插
        $lock = Cache::lock('TELEGRAM_ORDER_LOCK_' . $user->id, 10);
        if (!$lock->get()) {
            abort(500, '操作过于频繁，请稍后再试');
        }
        try {
            $order = Order::where('trade_no', $tradeNo)
                ->where('user_id', $user->id)
                ->first();
            if (!$order) {
                abort(500, '订单不存在');
            }
            if ((int)$order->status !== 0) {
                abort(500, '只能取消待支付订单');
            }
            $orderService = new OrderService($order);
            if (!$orderService->cancel()) {
                abort(500, '取消失败，请稍后再试');
            }
            // 移除历史消息上残留的支付/取消按钮
            self::clearPayMessages($tradeNo);
            return $order;
        } finally {
            $lock->release();
        }
    }

    // 发送下单结果并记录带支付按钮的消息位置，供订单终态时移除按钮
    public function sendResult(TelegramService $telegramService, int $chatId, array $result): void
    {
        $markup = $this->buildResultMarkup($result);
        $response = $telegramService->sendMessage($chatId, $this->buildResultMessage($result), '', $markup);
        if ($markup && isset($response->result->message_id)) {
            self::rememberPayMessage($result['trade_no'], $chatId, $response->result->message_id);
        }
    }

    // 记录带支付按钮的消息位置，订单到终态（开通/取消）后据此移除旧消息上的按钮
    public static function rememberPayMessage(string $tradeNo, int $chatId, int $messageId): void
    {
        $key = 'TG_PAY_MSG_' . $tradeNo;
        $list = Cache::get($key, []);
        $pair = [$chatId, $messageId];
        if (in_array($pair, $list)) return;
        $list[] = $pair;
        // 只保留最近3条，控制终态清理时的 TG 接口调用次数
        $list = array_slice($list, -3);
        // TTL 需覆盖订单2小时未支付自动取消窗口
        Cache::put($key, $list, 3 * 3600);
    }

    // 移除该订单历史消息上的支付按钮；Cache::pull 保证只清理一次，失败不影响主流程
    public static function clearPayMessages(string $tradeNo): void
    {
        $list = Cache::pull('TG_PAY_MSG_' . $tradeNo);
        if (!$list) return;
        $telegramService = new TelegramService();
        foreach ($list as $pair) {
            try {
                $telegramService->editMessageReplyMarkup((int)$pair[0], (int)$pair[1]);
            } catch (\Exception $e) {
                // 消息已被删除等场景忽略
            }
        }
    }

    public function buildResultMessage(array $result): string
    {
        if ($result['status'] === 'opened') {
            return "下单成功，已使用余额支付，订阅正在开通中\n订单号：{$result['trade_no']}\n开通完成后会在此通知您。";
        }
        $amount = sprintf('%.2f', $result['total_amount'] / 100);
        $text = "订单创建成功\n———————————————\n订单号：{$result['trade_no']}\n待支付金额：{$amount} 元\n";
        if ($this->hasEnabledPayment()) {
            $text .= "请选择支付方式，并在2小时内完成支付（超时自动取消）";
        } elseif ($this->isValidPayUrl($result['pay_url'])) {
            // 未配置支付方式时回退网页支付
            $text .= "请在2小时内点击下方按钮完成支付（超时自动取消）";
        } else {
            // 支付链接非合法 http(s) 绝对地址时无法用按钮，退化为文本附带链接
            $text .= "请在2小时内打开以下链接完成支付（超时自动取消）：\n{$result['pay_url']}";
        }
        $text .= "\n支付完成后会在此通知您开通结果。";
        return $text;
    }

    public function buildResultMarkup(array $result)
    {
        if (($result['status'] ?? '') !== 'pending') return null;
        // 优先在机器人内选择支付方式，直接发起支付；无可用支付方式时回退网页支付链接
        $markup = $this->buildPaymentSelectMarkup($result['trade_no']);
        if ($markup) return $markup;
        if (!$this->isValidPayUrl($result['pay_url'])) return null;
        return [
            'inline_keyboard' => [
                [['text' => '去支付', 'url' => $result['pay_url']]]
            ]
        ];
    }

    // 待支付订单的支付方式选择键盘；无可用支付方式时返回 null
    public function buildPaymentSelectMarkup(string $tradeNo)
    {
        $payments = Payment::where('enable', 1)
            ->whereNotIn('payment', self::CLIENT_TOKEN_PAYMENTS)
            ->orderBy('sort', 'ASC')
            ->get();
        if ($payments->isEmpty()) return null;
        $keyboard = [];
        $row = [];
        foreach ($payments as $payment) {
            $row[] = [
                'text' => $payment->name,
                'callback_data' => "pay:{$tradeNo}:{$payment->id}"
            ];
            if (count($row) === 2) {
                $keyboard[] = $row;
                $row = [];
            }
        }
        if ($row) $keyboard[] = $row;
        return ['inline_keyboard' => $keyboard];
    }

    public function buildCheckoutMessage(array $checkout): string
    {
        // type -1：免支付直接开通；type 2：网关同步扣款，data 为真即已扣款成功
        if ($checkout['type'] === -1 || ($checkout['type'] === 2 && $checkout['data'])) {
            return "订单已支付完成，订阅正在开通中\n订单号：{$checkout['trade_no']}\n开通完成后会在此通知您。";
        }
        if ($checkout['type'] === 2) {
            return "支付未成功，请重新选择支付方式\n订单号：{$checkout['trade_no']}";
        }
        $amount = sprintf('%.2f', $checkout['pay_amount'] / 100);
        $text = "订单号：{$checkout['trade_no']}\n支付方式：{$checkout['payment_name']}\n应付金额：{$amount} 元\n———————————————\n";
        if ($this->isValidPayUrl($checkout['data'])) {
            $text .= "请点击下方“去支付”按钮完成支付";
        } elseif (is_string($checkout['data']) && $checkout['data'] !== '') {
            // 非 http(s) 链接（如 weixin:// 收款码内容）无法做成按钮，退化为文本供复制打开
            $text .= "请复制以下链接，使用对应支付App打开完成支付：\n{$checkout['data']}";
        } else {
            $text .= "支付请求已提交，请留意开通通知";
        }
        $text .= "\n支付完成后会在此通知您开通结果。";
        return $text;
    }

    public function buildCheckoutMarkup(array $checkout)
    {
        // 已支付完成（免支付或同步扣款成功）不再出任何支付按钮，避免重复扣款
        if ($checkout['type'] === -1 || ($checkout['type'] === 2 && $checkout['data'])) return null;
        $rows = [];
        if ($this->isValidPayUrl($checkout['data'])) {
            $rows[] = [['text' => '去支付', 'url' => $checkout['data']]];
        }
        $rows[] = [['text' => '« 重新选择支付方式', 'callback_data' => 'pay:' . $checkout['trade_no']]];
        return ['inline_keyboard' => $rows];
    }

    private function hasEnabledPayment(): bool
    {
        return Payment::where('enable', 1)
            ->whereNotIn('payment', self::CLIENT_TOKEN_PAYMENTS)
            ->exists();
    }

    // 生成 TG 机器人下单的网页支付链接：优先使用后台单独配置的支付域名，未配置时回退到站点网址
    public static function buildPayUrl(string $tradeNo): string
    {
        $payRedirect = '/#/order/' . $tradeNo;
        $payDomain = config('v2board.telegram_payment_domain') ?: config('v2board.app_url');
        return $payDomain ? rtrim($payDomain, '/') . $payRedirect : url($payRedirect);
    }

    // 订阅开通成功通知（余额支付/网页支付完成后由 OrderHandleJob 触发）
    public static function buildOpenedNotification(Order $order, User $user = null): string
    {
        if (!$user) $user = User::find($order->user_id);
        $plan = Plan::find($order->plan_id);
        $planName = $plan ? $plan->name : '订阅';

        if ($order->period === 'reset_price') {
            return "✅ 流量重置包开通成功\n———————————————\n订单号：{$order->trade_no}\n套餐：{$planName}\n您的流量已重置，可发送 /traffic 查看。";
        }

        $lines = [];
        $lines[] = "✅ 订阅开通成功";
        $lines[] = "———————————————";
        $lines[] = "订单号：{$order->trade_no}";
        $periodName = self::PERIOD_NAMES[$order->period] ?? '';
        $lines[] = "套餐：{$planName}" . ($periodName ? "（{$periodName}）" : '');
        if ($user) {
            $lines[] = $user->expired_at
                ? ('到期时间：' . date('Y-m-d H:i', $user->expired_at))
                : '到期时间：长期有效';
        }
        $lines[] = "";
        $lines[] = "可发送 /subscribe 获取订阅链接，/traffic 查看流量。";
        return implode("\n", $lines);
    }

    // 订单超时取消通知（2 小时未支付，由 OrderHandleJob 触发）
    public static function buildCanceledNotification(Order $order): string
    {
        return "⏰ 订单已超时取消\n———————————————\n订单号：{$order->trade_no}\n该订单超过2小时未完成支付，已自动取消。如需购买请发送 /buy。";
    }

    private function isValidPayUrl($url): bool
    {
        return is_string($url)
            && filter_var($url, FILTER_VALIDATE_URL) !== false
            && preg_match('#^https?://#i', $url) === 1;
    }
}
