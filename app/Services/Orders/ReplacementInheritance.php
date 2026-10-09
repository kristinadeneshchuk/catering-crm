<?php

namespace App\Services\Orders;

use App\Models\Order;
use App\Models\OrderReplacement;
use Illuminate\Support\Facades\DB;

/**
 * Заміни клієнта переходять у нове замовлення.
 *
 * Заміни живуть на замовленні (order_replacements), тож при продовженні
 * кухня бачила «чисте» меню, доки менеджер не вносив усе заново.
 *
 * Джерело — останнє попереднє замовлення клієнта, що НЕ перетинається
 * з новим за датами: паралельне замовлення — це інша людина (сімʼя, колега),
 * її заміни чужі. Дочірні замовлення не успадковують і не є джерелом.
 * Якщо в останньому замовленні замін немає — не копіюємо нічого:
 * менеджер міг прибрати їх свідомо.
 */
class ReplacementInheritance
{
    public function sourceFor(Order $order): ?Order
    {
        if ($order->parent_order_id || ! $order->client_id) {
            return null;
        }

        return Order::query()
            ->where('client_id', $order->client_id)
            ->where('id', '<', $order->id)
            ->whereNull('parent_order_id')
            ->when($order->start_date && $order->end_date, fn ($q) => $q->where(fn ($q) => $q
                ->whereNull('start_date')
                ->orWhereNull('end_date')
                ->orWhereDate('end_date', '<', $order->start_date)
                ->orWhereDate('start_date', '>', $order->end_date)))
            ->orderByDesc('end_date')
            ->orderByDesc('id')
            ->first();
    }

    /** Скільки замін скопійовано. Замовлення з власними замінами не чіпаємо. */
    public function inherit(Order $order): int
    {
        if ($order->replacements()->exists()) {
            return 0;
        }

        $source = $this->sourceFor($order);
        if (! $source) {
            return 0;
        }

        return DB::transaction(function () use ($order, $source) {
            $copied = 0;
            foreach ($source->replacements()->get() as $rep) {
                $copy = $rep->replicate(['order_id']);
                $copy->order_id = $order->id;
                $copy->save();
                $copied++;
            }

            return $copied;
        });
    }
}
