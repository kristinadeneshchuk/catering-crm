<?php

namespace App\Services\Messaging;

/**
 * Канал SMS.
 *
 * Один інтерфейс на всі повідомлення клієнту: код входу, нагадування про
 * повернення, що з'явиться далі. Шлюзу в проєкті ще немає, і саме тому канал
 * винесений окремо — коли з'явиться договір з оператором, пишеться один клас
 * і міняється рядок у `config/clients.php`.
 */
interface Sms
{
    public function send(string $phone, string $text): void;
}
