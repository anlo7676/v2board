<?php

namespace App\Services;

use App\Utils\CacheKey;
use Illuminate\Support\Facades\Cache;

class TelegramSessionService
{
    CONST TTL = 600;

    public function get($chatId)
    {
        return Cache::get(CacheKey::get('TELEGRAM_SESSION', $chatId));
    }

    public function set($chatId, array $state, int $ttl = self::TTL): void
    {
        Cache::put(CacheKey::get('TELEGRAM_SESSION', $chatId), $state, $ttl);
    }

    public function forget($chatId): void
    {
        Cache::forget(CacheKey::get('TELEGRAM_SESSION', $chatId));
    }
}
