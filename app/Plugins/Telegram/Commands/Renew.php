<?php

namespace App\Plugins\Telegram\Commands;

use App\Models\Plan;
use App\Models\User;
use App\Plugins\Telegram\Telegram;
use App\Services\TelegramOrderService;

class Renew extends Telegram {
    public $command = '/renew';
    public $description = '续费当前订阅';
    public $sort = 7;
    public $callback = 'renew';

    public function handle($message, $match = []) {
        if (!$message->is_private) return;
        $user = User::where('telegram_id', $message->chat_id)->first();
        if (!$user) {
            $this->telegramService->sendMessage($message->chat_id, '没有查询到您的用户信息，请先使用 /login 登录或 /bind 绑定账号');
            return;
        }
        if (!$user->plan_id) {
            $this->telegramService->sendMessage($message->chat_id, '您还没有订阅，请使用 /buy 购买');
            return;
        }
        $plan = Plan::find($user->plan_id);
        if (!$plan) {
            abort(500, '当前订阅不存在，请使用 /buy 重新购买');
        }
        if (!$plan->renew) {
            abort(500, '该订阅无法续费，请使用 /buy 更换其它订阅');
        }

        if ($message->message_type === 'callback_query' && strpos($message->callback_data, 'renew:period:') === 0) {
            $period = explode(':', $message->callback_data)[2] ?? '';
            $orderService = new TelegramOrderService();
            $result = $orderService->createAndCheckout($user, $plan->id, $period);
            $this->telegramService->sendMessage($message->chat_id, $orderService->buildResultMessage($result));
            return;
        }

        $this->showPeriods($message, $user, $plan);
    }

    private function showPeriods($message, User $user, Plan $plan)
    {
        $keyboard = [];
        $row = [];
        foreach (TelegramOrderService::PERIODS as $period) {
            // 流量重置包由 /reset 提供
            if ($period === 'reset_price') continue;
            if ($plan[$period] === NULL) continue;
            $price = sprintf('%.2f', $plan[$period] / 100);
            $row[] = [
                'text' => TelegramOrderService::PERIOD_NAMES[$period] . " {$price}元",
                'callback_data' => "renew:period:{$period}"
            ];
            if (count($row) === 2) {
                $keyboard[] = $row;
                $row = [];
            }
        }
        if ($row) $keyboard[] = $row;
        if (!$keyboard) {
            abort(500, '该订阅暂无可续费的周期');
        }
        $expiredDate = $user->expired_at ? date('Y-m-d H:i', $user->expired_at) : '长期有效';
        $text = "续费订阅\n———————————————\n当前订阅：{$plan->name}\n到期时间：{$expiredDate}\n请选择续费周期：";
        $markup = ['inline_keyboard' => $keyboard];
        if ($message->message_type === 'callback_query') {
            $this->telegramService->editMessageText($message->chat_id, $message->message_id, $text, $markup);
        } else {
            $this->telegramService->sendMessage($message->chat_id, $text, '', $markup);
        }
    }
}
