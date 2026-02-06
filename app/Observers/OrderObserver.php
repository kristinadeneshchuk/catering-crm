<?php

namespace App\Observers;

use App\Models\Order;

class OrderObserver
{
    /**
     * Спрацьовує ПЕРЕД тим, як замовлення буде створено в базі.
     * Тут ми визначаємо: це новий клієнт чи постійний.
     */
    public function creating(Order $order): void
    {
        // Якщо статус не обрано вручну (або він 'new'), вмикаємо авто-логіку
        if (empty($order->status) || $order->status === 'new') {
            
            // Перевіряємо, чи є у цього клієнта хоча б одне замовлення в базі
            // (Поточне ще не збереглося, тому воно не рахується)
            $hasHistory = Order::where('client_id', $order->client_id)->exists();

            if ($hasHistory) {
                // Якщо історія є — значить клієнт постійний -> Active
                $order->status = 'active';
            } else {
                // Якщо історії немає — це перше замовлення -> New
                $order->status = 'new';
            }
        }
    }

    /**
     * Handle the Order "created" event.
     */
    public function created(Order $order): void
    {
        //
    }

    /**
     * Handle the Order "updated" event.
     */
    public function updated(Order $order): void
    {
        //
    }

    /**
     * Handle the Order "deleted" event.
     */
    public function deleted(Order $order): void
    {
        //
    }

    /**
     * Handle the Order "restored" event.
     */
    public function restored(Order $order): void
    {
        //
    }

    /**
     * Handle the Order "force deleted" event.
     */
    public function forceDeleted(Order $order): void
    {
        //
    }
}