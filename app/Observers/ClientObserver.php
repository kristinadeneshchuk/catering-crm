<?php

namespace App\Observers;

use App\Models\Client;
use App\Models\Order;

class ClientObserver
{
    /**
     * Спрацьовує при оновленні клієнта.
     */
    public function updated(Client $client): void
    {
        // 1. Перевіряємо, чи змінилося саме поле цільових калорій
        if ($client->wasChanged('target_kcal')) {
            
            // 2. Отримуємо всі замовлення клієнта, які ще в роботі
            // (Завершеним замовленням калорії міняти не варто для історії)
            $activeOrders = $client->orders()
                ->whereIn('status', ['new', 'active', 'paused'])
                ->get();

            foreach ($activeOrders as $order) {
                // 3. Оновлюємо калорії в кожному замовленні
                $order->update([
                    'calories' => $client->target_kcal
                ]);
            }
        }
    }

    /**
     * Решта методів залишаються порожніми (заглушки)
     */
    public function created(Client $client): void {}

    public function deleted(Client $client): void {}

    public function restored(Client $client): void {}

    public function forceDeleted(Client $client): void {}
}