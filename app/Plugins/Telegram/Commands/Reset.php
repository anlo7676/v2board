<?php

namespace App\Plugins\Telegram\Commands;

use App\Models\Plan;
use App\Models\User;
use App\Plugins\Telegram\Telegram;
use App\Services\TelegramOrderService;
use App\Services\UserService;
use App\Utils\Helper;

class Reset extends Telegram {
    public $command = '/reset';
    public $description = '购买流量重置包';
    public $sort = 8;
    public $callback = 'reset';

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
        if (!$plan || $plan->reset_price === NULL) {
            abort(500, '当前订阅不支持购买流量重置包');
        }
        $userService = new UserService();
        if (!$userService->isAvailable($user)) {
            abort(500, '订阅已过期或无有效订阅，无法购买流量重置包');
        }

        if ($message->message_type === 'callback_query' && $message->callback_data === 'reset:confirm') {
            $orderService = new TelegramOrderService();
            $result = $orderService->createAndCheckout($user, $plan->id, 'reset_price');
            $this->telegramService->sendMessage($message->chat_id, $orderService->buildResultMessage($result), '', $orderService->buildResultMarkup($result));
            return;
        }

        $price = sprintf('%.2f', $plan->reset_price / 100);
        $used = Helper::trafficConvert($user->u + $user->d);
        $total = Helper::trafficConvert($user->transfer_enable);
        $text = "流量重置包\n———————————————\n当前订阅：{$plan->name}\n已用流量：{$used} / {$total}\n重置价格：{$price} 元\n购买开通后已用流量将立即清零，确认购买？";
        $markup = [
            'inline_keyboard' => [
                [['text' => "确认购买（{$price}元）", 'callback_data' => 'reset:confirm']]
            ]
        ];
        if ($message->message_type === 'callback_query') {
            $this->telegramService->editMessageText($message->chat_id, $message->message_id, $text, $markup);
        } else {
            $this->telegramService->sendMessage($message->chat_id, $text, '', $markup);
        }
    }
}
