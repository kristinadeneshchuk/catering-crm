<?php

namespace App\Observers;

use App\Models\OrderDay;

class OrderDayObserver
{
    /**
     * При додаванні дня — перерахунок статусу замовлення
     * (щоб finished автоматично оживало в active/new, якщо знову є майбутні дні).
     */
    public function created(OrderDay $orderDay): void
    {
        $orderDay->order?->refresh()->recomputeStatus();
    }

    /**
     * При видаленні дня — той самий перерахунок
     * (щоб замовлення без майбутніх днів автоматично ставало finished).
     */
    public function deleted(OrderDay $orderDay): void
    {
        $orderDay->order?->refresh()->recomputeStatus();
    }

    /**
     * Метод для розрахунків також вимкнений.
     */
    private function processPayment(OrderDay $orderDay, string $action)
    {
        // Метод не використовується
    }
}