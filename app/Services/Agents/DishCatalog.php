<?php

namespace App\Services\Agents;

use App\Models\Dish;
use App\Models\Order;
use App\Models\OrderDayDish;
use Illuminate\Support\Facades\DB;

/**
 * Пошук готових страв для агента: спершу збираємо меню з того, що вже є
 * (індивідуальні інших клієнтів і цикл), нову страву — лише коли нічого не підходить.
 */
class DishCatalog
{
    /**
     * @param array{q?: string, ingredient?: string, kind?: string, order_id?: int, limit?: int} $f
     */
    public function search(array $f): array
    {
        $cycleIds = DB::table('daily_menu_dishes')->distinct()->pluck('dish_id')->flip();
        // Середні ціни одним запитом, а не по запиту на кожен продукт.
        \App\Models\Ingredient::preloadAveragePrices();

        $q = Dish::query()->with(['dishIngredients.ingredient', 'dishIngredients.childDish.dishIngredients.ingredient'])
            ->where(fn ($w) => $w->whereNull('is_semi_finished')->orWhere('is_semi_finished', false));
        if (!empty($f['q'])) $q->where('name', 'like', '%' . $f['q'] . '%');
        if (!empty($f['ingredient'])) {
            $ingIds = DB::table('ingredients')->where('name', 'like', '%' . $f['ingredient'] . '%')->pluck('id');
            $q->whereHas('dishIngredients', fn ($w) => $w->whereIn('ingredient_id', $ingIds)
                ->orWhereHas('childDish.dishIngredients', fn ($c) => $c->whereIn('ingredient_id', $ingIds)));
        }
        $kind = $f['kind'] ?? 'all';
        if ($kind === 'individual') $q->where('name', 'like', 'Інд ·%');
        if ($kind === 'cycle') $q->whereIn('id', $cycleIds->keys());

        $dishes = $q->orderBy('name')->limit(min((int) ($f['limit'] ?? 40), 100))->get();

        // Виключення клієнта — щоб агент одразу бачив, що страва не підходить.
        $order = !empty($f['order_id']) ? Order::with(['client.ingredientExclusions', 'client.dishExclusions', 'client.replacementBundles.items.originalIngredient', 'ingredientExclusions'])->find($f['order_id']) : null;
        $excluded = $order ? $order->effectiveExcludedIngredients()->pluck('name', 'id') : collect();
        $excludedDishes = $order?->client?->dishExclusions?->pluck('id')->flip() ?? collect();

        $served = OrderDayDish::query()
            ->whereIn('dish_id', $dishes->pluck('id'))
            ->whereDate('date', '>=', now()->subDays(IndividualWeekMenu::NO_REPEAT_DAYS))
            ->join('orders', 'orders.id', '=', 'order_day_dishes.order_id')
            ->get(['order_day_dishes.dish_id', 'order_day_dishes.date', 'orders.client_id'])
            ->groupBy('dish_id');

        return $dishes->map(function (Dish $d) use ($cycleIds, $excluded, $excludedDishes, $served, $order) {
            $t = $d->calculated_totals;
            $w = (float) ($t['output_weight'] ?: 1);
            $ingIds = $this->ingredientIds($d);
            $byMe = $order ? ($served[$d->id] ?? collect())->where('client_id', $order->client_id)->pluck('date')->map(fn ($x) => substr((string) $x, 0, 10))->unique()->values() : collect();

            return [
                'id' => $d->id,
                'name' => $d->name,
                'group' => $d->group,
                'kind' => $cycleIds->has($d->id) ? 'cycle' : (str_starts_with($d->name, 'Інд ·') ? 'individual' : 'other'),
                'base_weight_g' => round($w),
                'per_100g' => [
                    'kcal' => round($t['kcal'] / $w * 100), 'p' => round($t['prot'] / $w * 100, 1),
                    'f' => round($t['fat'] / $w * 100, 1), 'c' => round($t['carb'] / $w * 100, 1),
                    'cost' => round($t['cost'] / $w * 100, 2),
                ],
                'conflicts' => array_values(array_filter(array_merge(
                    $excludedDishes->has($d->id) ? ['клієнт не їсть цю страву'] : [],
                    collect($ingIds)->map(fn ($id) => $excluded[$id] ?? null)->filter()->unique()->values()->all(),
                ))),
                'served_to_client_last_28d' => $byMe->all(),
                'clients_last_28d' => ($served[$d->id] ?? collect())->pluck('client_id')->unique()->count(),
            ];
        })->values()->all();
    }

    /** Усі продукти страви з напівфабрикатами (до 3 рівнів). */
    private function ingredientIds(Dish $d, int $depth = 0): array
    {
        $ids = [];
        foreach ($d->dishIngredients as $di) {
            if ($di->ingredient_id) $ids[] = (int) $di->ingredient_id;
            if ($di->childDish && $depth < 3) $ids = array_merge($ids, $this->ingredientIds($di->childDish, $depth + 1));
        }

        return $ids;
    }
}
