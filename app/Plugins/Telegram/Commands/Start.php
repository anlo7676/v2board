<?php

namespace App\Plugins\Telegram\Commands;

use App\Models\User;
use App\Plugins\Telegram\Telegram;

class Start extends Telegram {
    public $command = '/start';
    public $description = '开始使用机器人';
    public $sort = 1;

    public function handle($message, $match = []) {
        if (!$message->is_private) return;
        $telegramService = $this->telegramService;
        $user = User::where('telegram_id', $message->chat_id)->first();

        // deep link 一键绑定：网站个人中心生成 https://t.me/{bot}?start=bind_{token}，点击后自动携带参数触发 /start
        $startParam = isset($message->args[0]) ? trim($message->args[0]) : '';
        if (strpos($startParam, 'bind_') === 0) {
            $this->handleBindDeepLink($message, substr($startParam, 5));
            return;
        }

        // 已绑定但被停用的账户：提示后直接返回，不展示购买/续费等菜单
        if ($user && $user->banned) {
            $telegramService->sendMessage($message->chat_id, '您的账户已被停止使用，如有疑问请联系管理员');
            return;
        }

        // 同步该会话命令菜单，确保与绑定状态一致（已绑定隐藏注册/登录）
        if ($user) {
            $telegramService->applyChatCommands($message->chat_id, 'member');
        } else {
            $telegramService->resetChatCommands($message->chat_id);
        }

        $lines = [];
        $lines[] = sprintf("欢迎使用 %s 机器人", config('v2board.app_name', 'V2Board'));
        $lines[] = "———————————————";
        if (!$user) {
            $lines[] = "请先注册或登录绑定账号后使用下单/订阅等功能：";
            $lines[] = "/register 注册账号";
            $lines[] = "/login 登录并绑定账号";
            $lines[] = "/bind 订阅链接 绑定已有账号（也可在网站个人中心点击“绑定Telegram”一键完成）";
        } else {
            $lines[] = "请点击下方按钮操作，或使用以下命令：";
            $lines[] = "/buy 购买订阅";
            $lines[] = "/renew 续费当前订阅";
            $lines[] = "/reset 购买流量重置包";
            $lines[] = "/traffic 查询套餐信息";
            $lines[] = "/subscribe 获取/重置订阅链接";
            $lines[] = "/orders 查询我的订单";
            $lines[] = "/unbind 解绑账号";
        }
        $lines[] = "/cancel 取消当前操作";
        $text = implode("\n", $lines);

        $rows = [];
        if (!$user) {
            $rows[] = [
                ['text' => '注册账号', 'callback_data' => 'register:start'],
                ['text' => '登录', 'callback_data' => 'login:start']
            ];
        } else {
            $rows[] = [
                ['text' => '购买订阅', 'callback_data' => 'buy'],
                ['text' => '续费', 'callback_data' => 'renew'],
                ['text' => '重置流量', 'callback_data' => 'reset']
            ];
            $rows[] = [
                ['text' => '订阅链接', 'callback_data' => 'subscribe'],
                ['text' => '我的订单', 'callback_data' => 'orders']
            ];
        }

        $telegramService->sendMessage($message->chat_id, $text, '', ['inline_keyboard' => $rows]);
    }

    // deep link 绑定：参数为订阅 token（或订阅链接），解析后完成绑定并展示成员首页
    private function handleBindDeepLink($message, string $payload)
    {
        if ($payload === '') {
            $this->telegramService->sendMessage($message->chat_id, '绑定链接无效，请到网站个人中心重新获取');
            return;
        }
        $existing = User::where('telegram_id', $message->chat_id)->first();
        if ($existing) {
            $this->telegramService->sendMessage($message->chat_id, "当前Telegram已绑定账号：{$existing->email}，如需更换请先发送 /unbind 解绑");
            return;
        }
        $token = $this->resolveSubscribeToken($payload);
        $user = User::where('token', $token)->first();
        if (!$user) {
            $this->telegramService->sendMessage($message->chat_id, '未找到该绑定链接对应的用户，请到网站个人中心重新获取');
            return;
        }
        if ($user->banned) {
            $this->telegramService->sendMessage($message->chat_id, '该账户已被停止使用，如有疑问请联系管理员');
            return;
        }
        $this->bindTelegram($user, $message->chat_id);
        $this->telegramService->sendMessage($message->chat_id, "绑定成功，当前账号：{$user->email}\n发送 /start 或 /help 查看可用功能");
    }
}
