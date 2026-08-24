<?php

namespace App\Services\Clients;

use Illuminate\Support\Facades\Log;

/**
 * Тимчасовий канал: код іде в лог.
 *
 * Ним можна користуватися на тестовому майданчику (менеджер бере код із логів),
 * але не на бойовому — там мусить стояти SMS. Щоб про це не забули, клас
 * пише в лог попередження, а не звичайний рядок.
 */
class LogCodeSender implements CodeSender
{
    public function send(string $phone, string $code): void
    {
        Log::warning('Код входу в кабінет надіслано в лог, а не SMS', [
            'phone' => $phone,
            'code' => $code,
        ]);
    }
}
