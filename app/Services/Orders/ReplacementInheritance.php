<?php

namespace App\Services\Orders;

use App\Models\Order;
use App\Models\OrderReplacement;
use Illuminate\Support\Collection;
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
 *
 * Правка заміни в живому замовленні (new/active/paused) дзеркалиться в інші
 * живі замовлення клієнта: у поточне, якщо правили вже створене продовження,
 * і навпаки. Якщо серед них є паралельні (сімʼя) — не синхронізуємо нічого.
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

        // Копія — не правка менеджера, дзеркалити її нікуди не треба.
        self::$syncing = true;
        try {
            return $this->copy($order, $source);
        } finally {
            self::$syncing = false;
        }
    }

    private function copy(Order $order, Order $source): int
    {
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

    private const LIVE = ['new', 'active', 'paused'];

    private static bool $syncing = false;

    /** Інші живі замовлення тієї ж людини. Порожньо, якщо незрозуміло, чия це заміна. */
    public function liveSiblings(Order $order): Collection
    {
        if ($order->parent_order_id || ! in_array($order->status, self::LIVE, true)) {
            return collect();
        }

        $live = Order::query()
            ->where('client_id', $order->client_id)
            ->whereNull('parent_order_id')
            ->whereIn('status', self::LIVE)
            ->get();

        $overlaps = fn (Order $a, Order $b) => ! $a->start_date || ! $a->end_date || ! $b->start_date || ! $b->end_date
            || ($a->start_date->lte($b->end_date) && $b->start_date->lte($a->end_date));

        foreach ($live as $i => $a) {
            foreach ($live->slice($i + 1) as $b) {
                if ($overlaps($a, $b)) {
                    return collect();
                }
            }
        }

        return $live->where('id', '!=', $order->id)->values();
    }

    public function syncSaved(OrderReplacement $rep): void
    {
        $this->mirror($rep, fn (Order $target) => OrderReplacement::updateOrCreate(
            ['order_id' => $target->id, 'dish_id' => $rep->dish_id, 'original_product_id' => $rep->original_product_id],
            [
                'replacement_product_id' => $rep->replacement_product_id,
                'replacement_dish_id'    => $rep->replacement_dish_id,
                'force_approved'         => (bool) $rep->force_approved,
                'comment'                => $rep->comment,
            ],
        ));
    }

    public function syncDeleted(OrderReplacement $rep): void
    {
        $this->mirror($rep, fn (Order $target) => OrderReplacement::query()
            ->where('order_id', $target->id)
            ->where('dish_id', $rep->dish_id)
            ->where('original_product_id', $rep->original_product_id)
            ->delete());
    }

    private function mirror(OrderReplacement $rep, callable $apply): void
    {
        if (self::$syncing || ! $rep->order) {
            return;
        }

        self::$syncing = true;
        try {
            foreach ($this->liveSiblings($rep->order) as $target) {
                $apply($target);
            }
        } finally {
            self::$syncing = false;
        }
    }
}
