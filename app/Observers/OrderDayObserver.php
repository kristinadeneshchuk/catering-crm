<?php

namespace App\Observers;

use App\Models\OrderDay;

class OrderDayObserver
{
    /**
     * При додаванні дня — перерахунок end_date і статусу замовлення
     * (щоб finished автоматично оживало в active/new, якщо знову є майбутні дні).
     */
    public function created(OrderDay $orderDay): void
    {
        $this->syncOrder($orderDay);
    }

    /**
     * Якщо у дня змінилась дата — підлаштувати end_date парента.
     */
    public function updated(OrderDay $orderDay): void
    {
        if ($orderDay->wasChanged('date')) {
            $this->syncOrder($orderDay);
        }
    }

    /**
     * При видаленні дня — той самий перерахунок
     * (щоб замовлення без майбутніх днів автоматично ставало finished).
     */
    public function deleted(OrderDay $orderDay): void
    {
        $this->syncOrder($orderDay);
    }

    private function syncOrder(OrderDay $orderDay): void
    {
        $order = $orderDay->order;
        if (!$order) {
            return;
        }
        $order->refresh();
        $order->recomputeEndDate();
        $order->refresh()->recomputeStatus();
    }

    /**
     * Метод для розрахунків також вимкнений.
     */
    private function processPayment(OrderDay $orderDay, string $action)
    {
        // Метод не використовується
    }
}