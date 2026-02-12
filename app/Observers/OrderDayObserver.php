<?php

namespace App\Observers;

use App\Models\OrderDay;

class OrderDayObserver
{
    /**
     * Тимчасово вимкнено: Автоматичне списання при створенні дня не відбувається.
     */
    public function created(OrderDay $orderDay): void
    {
        // Логіка списання (charge) закоментована
    }

    /**
     * Тимчасово вимкнено: Автоматичне повернення при видаленні дня не відбувається.
     */
    public function deleted(OrderDay $orderDay): void
    {
        // Логіка повернення (refund) закоментована
    }

    /**
     * Метод для розрахунків також вимкнений.
     */
    private function processPayment(OrderDay $orderDay, string $action)
    {
        // Метод не використовується
    }
}