<?php

namespace App\Console\Commands;

use App\Jobs\SendTelegramJob;
use App\Services\MailService;
use App\Utils\Helper;
use Illuminate\Console\Command;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

class SendRemindMail extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'send:remindMail';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '发送提醒邮件';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        ini_set('memory_limit', -1);
        $users = User::all();
        $mailService = new MailService();
        foreach ($users as $user) {
            if ($user->remind_expire) {
                $mailService->remindExpire($user);
                $this->remindExpireTelegram($user);
            }
            if (!($user->expired_at !== NULL && $user->expired_at < time()) && $user->remind_traffic) {
                $mailService->remindTraffic($user);
                $this->remindTrafficTelegram($user);
            }
        }
    }

    // 到期提醒（TG 渠道）：与邮件相同的触发条件（24小时内到期），复用用户 remind_expire 开关，TG 侧按天去重
    private function remindExpireTelegram(User $user): void
    {
        if (!(int)config('v2board.telegram_bot_enable', 0)) return;
        if (!$user->telegram_id) return;
        if (!($user->expired_at !== NULL && ($user->expired_at - 86400) < time() && $user->expired_at > time())) return;
        if (!Cache::add('TG_REMIND_EXPIRE_' . $user->id . '_' . date('Ymd'), 1, 86400 * 2)) return;
        $expireText = date('Y-m-d H:i', $user->expired_at);
        $text = "⏳ 订阅到期提醒\n———————————————\n您的订阅将于 {$expireText} 到期。\n发送 /renew 可立即续费，避免服务中断。";
        SendTelegramJob::dispatch($user->telegram_id, $text, '', [
            'inline_keyboard' => [
                [['text' => '立即续费', 'callback_data' => 'renew']]
            ]
        ]);
    }

    // 流量告警（TG 渠道）：与邮件相同的触发条件（用量达95%且未耗尽），复用用户 remind_traffic 开关，TG 侧按天去重
    private function remindTrafficTelegram(User $user): void
    {
        if (!(int)config('v2board.telegram_bot_enable', 0)) return;
        if (!$user->telegram_id) return;
        $used = $user->u + $user->d;
        if (!$used || !$user->transfer_enable) return;
        $percentage = ($used / $user->transfer_enable) * 100;
        if ($percentage < 95 || $percentage >= 100) return;
        if (!Cache::add('TG_REMIND_TRAFFIC_' . $user->id . '_' . date('Ymd'), 1, 86400 * 2)) return;
        $usedText = Helper::trafficConvert($used);
        $totalText = Helper::trafficConvert($user->transfer_enable);
        $text = "⚠️ 流量告警\n———————————————\n您的套餐流量已使用 " . round($percentage) . "%（{$usedText} / {$totalText}），即将耗尽。\n发送 /reset 购买流量重置包，或 /traffic 查看详情。";
        SendTelegramJob::dispatch($user->telegram_id, $text, '', [
            'inline_keyboard' => [
                [['text' => '购买重置包', 'callback_data' => 'reset'], ['text' => '查看流量', 'callback_data' => 'traffic']]
            ]
        ]);
    }
}
