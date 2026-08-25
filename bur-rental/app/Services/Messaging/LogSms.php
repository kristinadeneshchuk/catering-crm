<?php

namespace App\Services\Messaging;

use Illuminate\Support\Facades\Log;

/**
 * Тимчасовий канал: SMS іде в лог.
 *
 * Ним можна жити на тестовому майданчику, але не на бойовому. Щоб про це не
 * забули, кожне повідомлення пишеться рівнем `warning`, а не звичайним рядком.
 */
class LogSms implements Sms
{
    public function send(string $phone, string $text): void
    {
        Log::warning('SMS не надіслано — шлюзу немає, повідомлення в логу', [
            'phone' => $phone,
            'text' => $text,
        ]);
    }
}
