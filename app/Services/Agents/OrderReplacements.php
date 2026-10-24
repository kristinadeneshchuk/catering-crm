<?php

namespace App\Services\Agents;

use App\Models\Dish;
use App\Models\Ingredient;
use App\Models\Order;
use App\Models\OrderReplacement;
use Illuminate\Support\Facades\DB;

/**
 * Пропозиція «заміни в стравах циклу»: продукт → продукт, уся страва → інша,
 * «без продукту» (replacement порожній) або «примусово одобрено».
 *
 * payload: order_id, mode: merge|replace,
 *   items: [{dish_id, original_product_id|null, replacement_product_id|null,
 *            replacement_dish_id|null, force_approved?, comment?}]
 */
class OrderReplacements
{
    public function validate(array $p): array
    {
        $e = [];
        if (!Order::whereKey($p['order_id'] ?? 0)->exists()) $e[] = 'order_id: замовлення не знайдено';
        if (!in_array($p['mode'] ?? 'merge', ['merge', 'replace'], true)) $e[] = 'mode: merge або replace';
        if (empty($p['items'])) $e[] = 'items: порожньо';
        $known = ['dish_id', 'original_product_id', 'replacement_product_id', 'replacement_dish_id', 'force_approved', 'comment'];
        foreach ((array) ($p['items'] ?? []) as $i => $it) {
            // Невідомі поля мовчки губились — і рядок без original_product_id ставав «прибрати всю страву».
            if ($unknown = array_diff(array_keys((array) $it), $known)) {
                $e[] = "items[$i]: невідомі поля " . implode(', ', $unknown) . ' — дозволено: ' . implode(', ', $known);
                continue;
            }
            if (empty($it['original_product_id']) && empty($it['replacement_dish_id']) && empty($it['force_approved'])) {
                $e[] = "items[$i]: вкажіть original_product_id (що міняємо/прибираємо) або replacement_dish_id (заміна всієї страви)";
            }
            if (!Dish::whereKey($it['dish_id'] ?? 0)->exists()) $e[] = "items[$i]: страви #" . ($it['dish_id'] ?? '?') . ' немає';
            foreach (['original_product_id', 'replacement_product_id'] as $k) {
                if (!empty($it[$k]) && !Ingredient::whereKey($it[$k])->exists()) $e[] = "items[$i].{$k}: продукту #{$it[$k]} немає";
            }
            if (!empty($it['replacement_dish_id']) && !Dish::whereKey($it['replacement_dish_id'])->exists()) $e[] = "items[$i].replacement_dish_id: страви немає";
            if (!empty($it['replacement_dish_id']) && !empty($it['original_product_id'])) $e[] = "items[$i]: заміна всієї страви — без original_product_id";
        }

        return $e;
    }

    public function apply(array $p): array
    {
        return DB::transaction(function () use ($p) {
            $order = Order::findOrFail($p['order_id']);
            $removed = 0;
            if (($p['mode'] ?? 'merge') === 'replace') {
                // По одній — щоб скидання дійшло й до повʼязаних живих замовлень клієнта.
                foreach (OrderReplacement::where('order_id', $order->id)->get() as $r) { $r->delete(); $removed++; }
            }
            foreach ($p['items'] as $it) {
                OrderReplacement::updateOrCreate(
                    ['order_id' => $order->id, 'dish_id' => $it['dish_id'], 'original_product_id' => $it['original_product_id'] ?? null],
                    [
                        'replacement_product_id' => $it['replacement_product_id'] ?? null,
                        'replacement_dish_id'    => $it['replacement_dish_id'] ?? null,
                        'force_approved'         => (bool) ($it['force_approved'] ?? false),
                        'comment'                => $it['comment'] ?? null,
                    ],
                );
            }

            return ['order_id' => $order->id, 'replacements' => count($p['items']), 'removed' => $removed];
        });
    }

    public function describe(array $p): string
    {
        $order = Order::with('client')->find($p['order_id'] ?? null);

        return trim(($order?->client?->name ?? 'Клієнт ?') . " (#{$order?->id})\nзамін: " . count($p['items'] ?? [])
            . (($p['mode'] ?? 'merge') === 'replace' ? ' (замість усіх наявних)' : ''));
    }
}
