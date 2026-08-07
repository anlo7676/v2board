<?php

namespace App\Jobs;

use App\Services\TelegramService;
use App\Services\TelegramUserUnreachableException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SendTelegramJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    protected $telegramId;
    protected $text;
    protected $parseMode = 'markdown';
    protected $replyMarkup;

    public $tries = 3;
    public $timeout = 10;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct(int $telegramId, string $text, string $parseMode = 'markdown', array $replyMarkup = null)
    {
        $this->onQueue('send_telegram');
        $this->telegramId = $telegramId;
        $this->text = $text;
        $this->parseMode = $parseMode;
        $this->replyMarkup = $replyMarkup;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        $telegramService = new TelegramService();
        try {
            // 兼容升级前已入队(无 parseMode 属性)的任务，默认 markdown
            $telegramService->sendMessage($this->telegramId, $this->text, $this->parseMode ?? 'markdown', $this->replyMarkup);
        } catch (TelegramUserUnreachableException $e) {
            // 用户拉黑机器人/账号停用：消息永远送不达，不再重试并记录，避免浪费队列资源
            Log::info('Telegram message undeliverable, user unreachable', [
                'telegram_id' => $this->telegramId,
                'reason' => $e->getMessage()
            ]);
            $this->delete();
        }
    }

    // 重试耗尽后记录日志，便于排查通知丢失
    public function failed(\Throwable $e)
    {
        Log::warning('SendTelegramJob failed after retries', [
            'telegram_id' => $this->telegramId,
            'error' => $e->getMessage()
        ]);
    }
}
