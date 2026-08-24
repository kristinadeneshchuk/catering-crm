<?php

namespace App\Services\Clients;

/**
 * Канал доставки одноразового коду.
 *
 * Окремий інтерфейс, бо SMS-шлюзу в проєкті ще немає, а кабінет має працювати
 * вже зараз. Коли з'явиться договір з оператором — пишеться один клас і
 * міняється `config('clients.code_sender')`; решта коду про це не знає.
 */
interface CodeSender
{
    public function send(string $phone, string $code): void;
}
