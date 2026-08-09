<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;

/**
 * Одне повідомлення менеджеру в Telegram.
 *
 * Через чергу, а не синхронно: недоступний Telegram не має ламати
 * бронювання. Без налаштованого токена джоба тихо виходить — сайт
 * повноцінно працює і без бота.
 */
class NotifyManagerInTelegram implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> секунди між спробами */
    public array $backoff = [10, 60];

    public function __construct(public readonly string $text) {}

    public function handle(): void
    {
        $token = config('services.telegram.bot_token');
        $chatId = config('services.telegram.chat_id');

        if (! $token || ! $chatId) {
            return;
        }

        Http::asForm()
            ->timeout(10)
            ->post("https://api.telegram.org/bot{$token}/sendMessage", [
                'chat_id' => $chatId,
                'text' => $this->text,
                'parse_mode' => 'HTML',
                'disable_web_page_preview' => true,
            ])
            ->throw();
    }
}
