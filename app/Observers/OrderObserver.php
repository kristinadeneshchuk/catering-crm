<?php

namespace App\Observers;

use App\Events\KitchenOrderEvent;
use App\Models\KitchenNotification;
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
     * Fires kitchen notification for cooks.
     */
    public function created(Order $order): void
    {
        // Skip child orders (additional rations within same family order)
        if ($order->parent_order_id) {
            return;
        }

        $order->loadMissing(['client.ingredientExclusions', 'projectData']);
        $client = $order->client;
        if (!$client) return;

        // Detect type: extension (returning client) or new
        $previousOrders = Order::where('client_id', $order->client_id)
            ->where('id', '!=', $order->id)
            ->exists();
        $type = $previousOrders ? 'extension' : 'new_client';

        $scheduleLabel = match($order->schedule_type) {
            'morning' => 'Ранок',
            'evening' => 'Вечір',
            default   => $order->schedule_type ?? '',
        };

        $typeLabel   = $type === 'extension' ? 'Продовження' : 'Новий клієнт';
        $projectName = $order->projectData?->name ?? $order->project ?? '';
        $hasExclusions = $client->ingredientExclusions?->isNotEmpty() ?? false;

        $message = "{$typeLabel}: {$client->name} — {$order->calories} ккал, {$order->duration} дн. ({$scheduleLabel})";

        $notification = KitchenNotification::create([
            'type'           => $type,
            'order_id'       => $order->id,
            'client_id'      => $client->id,
            'client_name'    => $client->name,
            'calories'       => (int) $order->calories,
            'schedule_type'  => $order->schedule_type,
            'project'        => $projectName,
            'has_exclusions' => $hasExclusions,
            'duration'       => (int) $order->duration,
            'start_date'     => $order->start_date,
            'message'        => $message,
        ]);

        broadcast(new KitchenOrderEvent($notification))->toOthers();
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