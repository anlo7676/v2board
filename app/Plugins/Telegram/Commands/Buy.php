<?php

namespace App\Plugins\Telegram\Commands;

use App\Models\Plan;
use App\Models\User;
use App\Plugins\Telegram\Telegram;
use App\Services\PlanService;
use App\Services\TelegramOrderService;

class Buy extends Telegram {
    public $command = '/buy';
    public $description = '购买订阅';
    public $sort = 6;
    public $callback = 'buy';

    public function handle($message, $match = []) {
        if (!$message->is_private) return;
        $user = User::where('telegram_id', $message->chat_id)->first();
        if (!$user) {
            $this->telegramService->sendMessage($message->chat_id, '没有查询到您的用户信息，请先使用 /login 登录或 /bind 绑定账号');
            return;
        }

        if ($message->message_type !== 'callback_query' || $message->callback_data === 'buy') {
            $this->showPlans($message);
            return;
        }

        $args = explode(':', $message->callback_data);
        if (($args[1] ?? '') === 'plan' && isset($args[2])) {
            $this->showPeriods($message, (int)$args[2]);
            return;
        }
        if (($args[1] ?? '') === 'period' && isset($args[2], $args[3])) {
            $this->submitOrder($message, $user, (int)$args[2], $args[3]);
            return;
        }
    }

    private function showPlans($message)
    {
        $counts = PlanService::countActiveUsers();
        $plans = Plan::where('show', 1)
            ->orderBy('sort', 'ASC')
            ->get();
        $keyboard = [];
        foreach ($plans as $plan) {
            $name = $plan->name;
            if ($plan->capacity_limit !== NULL) {
                $count = isset($counts[$plan->id]) ? $counts[$plan->id]->count : 0;
                if ($plan->capacity_limit - $count <= 0) {
                    continue;
                }
            }
            $keyboard[] = [[
                'text' => $name,
                'callback_data' => "buy:plan:{$plan->id}"
            ]];
        }
        if (!$keyboard) {
            abort(500, '当前没有可购买的订阅');
        }
        $markup = ['inline_keyboard' => $keyboard];
        $text = "购买订阅\n———————————————\n请选择订阅：";
        if ($message->message_type === 'callback_query') {
            $this->telegramService->editMessageText($message->chat_id, $message->message_id, $text, $markup);
        } else {
            $this->telegramService->sendMessage($message->chat_id, $text, '', $markup);
        }
    }

    private function showPeriods($message, int $planId)
    {
        $plan = Plan::find($planId);
        if (!$plan) {
            abort(500, '该订阅不存在');
        }
        $keyboard = [];
        $row = [];
        foreach (TelegramOrderService::PERIODS as $period) {
            // 流量重置包由 /reset 提供
            if ($period === 'reset_price') continue;
            if ($plan[$period] === NULL) continue;
            $price = sprintf('%.2f', $plan[$period] / 100);
            $row[] = [
                'text' => TelegramOrderService::PERIOD_NAMES[$period] . " {$price}元",
                'callback_data' => "buy:period:{$planId}:{$period}"
            ];
            if (count($row) === 2) {
                $keyboard[] = $row;
                $row = [];
            }
        }
        if ($row) $keyboard[] = $row;
        if (!$keyboard) {
            abort(500, '该订阅暂无可购买的周期');
        }
        $keyboard[] = [['text' => '« 返回订阅列表', 'callback_data' => 'buy']];
        $text = "订阅：{$plan->name}\n流量：{$plan->transfer_enable} GB\n———————————————\n请选择购买周期：";
        $this->telegramService->editMessageText($message->chat_id, $message->message_id, $text, ['inline_keyboard' => $keyboard]);
    }

    private function submitOrder($message, User $user, int $planId, string $period)
    {
        $orderService = new TelegramOrderService();
        $result = $orderService->createAndCheckout($user, $planId, $period);
        $this->telegramService->sendMessage($message->chat_id, $orderService->buildResultMessage($result), '', $orderService->buildResultMarkup($result));
    }
}
