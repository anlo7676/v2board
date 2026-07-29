<?php

namespace App\Services;

use App\Models\Order;
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

        // 余额全额抵扣，直接标记支付并异步开通
        if ($order->total_amount <= 0) {
            if (!$orderService->paid($order->trade_no)) {
                abort(500, '订单支付失败，请到网站订单页处理');
            }
            return [
                'status' => 'opened',
                'trade_no' => $order->trade_no
            ];
        }

        // TG 机器人下单优先使用后台单独配置的支付域名，未配置时回退到站点网址
        $payRedirect = '/#/order/' . $order->trade_no;
        $payDomain = config('v2board.telegram_payment_domain') ?: config('v2board.app_url');
        return [
            'status' => 'pending',
            'trade_no' => $order->trade_no,
            'total_amount' => $order->total_amount,
            'pay_url' => $payDomain ? rtrim($payDomain, '/') . $payRedirect : url($payRedirect)
        ];
    }

    public function buildResultMessage(array $result): string
    {
        if ($result['status'] === 'opened') {
            return "下单成功，已使用余额支付，订阅正在开通中\n订单号：{$result['trade_no']}";
        }
        $amount = sprintf('%.2f', $result['total_amount'] / 100);
        return "订单创建成功\n———————————————\n订单号：{$result['trade_no']}\n待支付金额：{$amount} 元\n请在2小时内完成支付（超时自动取消）：\n{$result['pay_url']}";
    }
}
