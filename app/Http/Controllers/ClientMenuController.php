<?php

namespace App\Http\Controllers;

use App\Models\DailyMenu;
use App\Models\Order;
use App\Models\OrderDayDish;
use App\Models\Setting;
use Carbon\Carbon;
use Illuminate\Http\Request;

class ClientMenuController extends Controller
{
    // =====================================================================
    // Головна сторінка: денне меню клієнта
    // =====================================================================
    public function show(string $token, Request $request)
    {
        $order = Order::where('menu_token', $token)
            ->with(['client.mealTypes', 'client.ingredientExclusions', 'client.dishExclusions'])
            ->firstOrFail();

        $client = $order->client;
        $today   = now()->startOfDay();
        $maxDate = $today->copy()->addDays(2); // дозволяємо сьогодні + 2 дні вперед
        $date = $request->input('date')
            ? Carbon::parse($request->input('date'))->startOfDay()
            : $today;

        // Клієнт не може переглядати далі ніж +2 дні
        if ($date->greaterThan($maxDate)) {
            $date = $today;
        }

        // Шукаємо активне замовлення на цю дату (може бути інше замовлення того ж клієнта)
        $activeOrder = Order::where('client_id', $client->id)
            ->whereHas('orderDays', fn($q) => $q->where('date', $date->format('Y-m-d')))
            ->with([
                'client.mealTypes',
                'client.ingredientExclusions',
                'client.dishExclusions',
                'replacements.originalProduct',
                'replacements.replacementProduct',
                'replacements.replacementDish.dishIngredients.ingredient',
                'tariff',
                'projectData',
            ])
            ->first();

        $items = [];
        $totals = ['kcal' => 0, 'prot' => 0, 'fat' => 0, 'carb' => 0];

        if ($activeOrder) {
            if ($activeOrder->menu_type === 'individual') {
                $result = $this->buildIndividualDayPlan($activeOrder, $date->format('Y-m-d'));
                $items  = $result['items'];
                $totals = $result['totals'];
            } else {
                [$menu] = $this->getMenuForDate($date);
                if ($menu) {
                    $result = $this->buildDayPlan($activeOrder, $menu);
                    $items  = $result['items'];
                    $totals = $result['totals'];
                }
            }
        }

        // Навігація: перевіряємо чи є замовлення на сусідні дні
        $prevDate = $date->copy()->subDay();
        $nextDate = $date->copy()->addDay();

        $hasPrev = Order::where('client_id', $client->id)
            ->whereHas('orderDays', fn($q) => $q->where('date', $prevDate->format('Y-m-d')))
            ->exists();

        $hasNext = !$nextDate->greaterThan($maxDate) && Order::where('client_id', $client->id)
            ->whereHas('orderDays', fn($q) => $q->where('date', $nextDate->format('Y-m-d')))
            ->exists();

        return view('menu.show', [
            'token'       => $token,
            'client'      => $client,
            'order'       => $activeOrder ?? $order,
            'date'        => $date,
            'items'       => $items,
            'totals'      => $totals,
            'hasPrev'     => $hasPrev,
            'hasNext'     => $hasNext,
            'prevDate'    => $prevDate,
            'nextDate'    => $nextDate,
        ]);
    }

    // =====================================================================
    // Деталі страви
    // =====================================================================
    public function dish(string $token, int $dishId, Request $request)
    {
        $order = Order::where('menu_token', $token)->firstOrFail();
        $client = $order->client;

        $date = $request->input('date')
            ? Carbon::parse($request->input('date'))->startOfDay()
            : now()->startOfDay();

        $activeOrder = Order::where('client_id', $client->id)
            ->whereHas('orderDays', fn($q) => $q->where('date', $date->format('Y-m-d')))
            ->with([
                'client.mealTypes',
                'client.ingredientExclusions',
                'client.dishExclusions',
                'replacements.originalProduct',
                'replacements.replacementProduct',
                'replacements.replacementDish.dishIngredients.ingredient',
            ])
            ->first() ?? $order->load([
                'client.mealTypes',
                'client.ingredientExclusions',
                'client.dishExclusions',
                'replacements.originalProduct',
                'replacements.replacementProduct',
                'replacements.replacementDish.dishIngredients.ingredient',
            ]);

        // Індивідуальний клієнт — беремо страву з персонального меню
        if ($activeOrder->menu_type === 'individual') {
            $pd = OrderDayDish::where('order_id', $activeOrder->id)
                ->where('date', $date->format('Y-m-d'))
                ->where('dish_id', $dishId)
                ->with(['dish.dishIngredients.ingredient.allergens', 'dish.dishIngredients.childDish.dishIngredients.ingredient.allergens', 'mealType'])
                ->first();

            if (!$pd || !$pd->dish) abort(404);

            $dish      = $pd->dish;
            $baseW     = (float)($dish->base_weight_g ?? 0);
            $totalKcal = (float)($dish->total_kcal ?? 0);
            $kcalPer100 = ($baseW > 0 && $totalKcal > 0) ? ($totalKcal / $baseW) * 100.0 : 0;

            $result      = $this->buildIndividualDayPlan($activeOrder, $date->format('Y-m-d'));
            $plannedDish = collect($result['items'])->firstWhere('dish_id', $dishId);
            $weight      = $plannedDish['weight'] ?? (int)$baseW;
            $k           = $baseW > 0 ? $weight / $baseW : 1.0;

            $dishKcal    = $weight * $kcalPer100 / 100.0;
            $dailyTarget = (float)$activeOrder->calories;

            return view('menu.dish', [
                'token'        => $token,
                'date'         => $date,
                'order'        => $activeOrder,
                'dish'         => $dish,
                'meal'         => $pd->mealType?->name ?? 'Прийом їжі',
                'weight'       => $weight,
                'kcal'         => round($dishKcal),
                'prot'         => round($plannedDish['prot'] ?? 0, 1),
                'fat'          => round($plannedDish['fat']  ?? 0, 1),
                'carb'         => round($plannedDish['carb'] ?? 0, 1),
                'pct_of_daily' => $dailyTarget > 0 ? round(($dishKcal / $dailyTarget) * 100) : 0,
                'ingredients'  => $this->getIngredientsWithReplacements($dish, $k, $activeOrder, $dish->id),
                'allergens'    => $this->collectAllergens($dish),
            ]);
        }

        [$menu] = $this->getMenuForDate($date);
        if (! $menu) abort(404);

        $menuItem = $menu->menuItems->firstWhere('dish_id', $dishId);
        if (! $menuItem || ! $menuItem->dish) abort(404);

        $dish = $menuItem->dish;

        $result = $this->buildDayPlan($activeOrder, $menu);
        $plannedDish = collect($result['items'])->firstWhere('dish_id', $dishId);
        if (! $plannedDish) abort(404);

        $weight    = $plannedDish['weight'];
        $baseWeight = (float) ($dish->base_weight_g ?? 0);
        $k = $baseWeight > 0 ? $weight / $baseWeight : 1.0;

        $ingredients = $this->getIngredientsWithReplacements($dish, $k, $activeOrder, $dish->id);
        $allergens   = $this->collectAllergens($dish);

        $dailyTarget = (float) $activeOrder->calories;
        $pctOfDaily  = $dailyTarget > 0 ? round(($plannedDish['kcal'] / $dailyTarget) * 100) : 0;

        return view('menu.dish', [
            'token'       => $token,
            'date'        => $date,
            'order'       => $activeOrder,
            'dish'        => $dish,
            'meal'        => $menuItem->mealType?->name ?? 'Прийом їжі',
            'weight'      => $weight,
            'kcal'        => round($plannedDish['kcal']),
            'prot'        => round($plannedDish['prot'], 1),
            'fat'         => round($plannedDish['fat'], 1),
            'carb'        => round($plannedDish['carb'], 1),
            'pct_of_daily' => $pctOfDaily,
            'ingredients' => $ingredients,
            'allergens'   => $allergens,
        ]);
    }

    // =====================================================================
    // Допоміжні методи
    // =====================================================================

    private function getMenuForDate(Carbon $date): array
    {
        $cycleDays    = (int) Setting::where('key', 'menu_cycle_days')->value('value') ?: 24;
        $startDateStr = Setting::where('key', 'menu_cycle_start_date')->value('value') ?: '2025-01-01';
        $anchorDate   = Carbon::parse($startDateStr);

        $globalDay = (abs($date->diffInDays($anchorDate)) % $cycleDays) + 1;

        $menu = DailyMenu::where('day_number', $globalDay)
            ->with([
                'menuItems.dish.dishIngredients.ingredient.allergens',
                'menuItems.dish.dishIngredients.childDish.dishIngredients.ingredient.allergens',
                'menuItems.mealType',
            ])
            ->first();

        return [$menu, $globalDay];
    }

    private function buildIndividualDayPlan(Order $order, string $date): array
    {
        $empty = ['items' => [], 'totals' => ['kcal' => 0.0, 'prot' => 0.0, 'fat' => 0.0, 'carb' => 0.0]];

        $personalDishes = OrderDayDish::where('order_id', $order->id)
            ->where('date', $date)
            ->with(['dish.dishIngredients.ingredient', 'mealType'])
            ->get();

        if ($personalDishes->isEmpty()) return $empty;

        $targetKcal = (float)($order->calories ?? 0);
        $count      = $personalDishes->count();
        $totals     = ['kcal' => 0.0, 'prot' => 0.0, 'fat' => 0.0, 'carb' => 0.0];
        $items      = [];

        foreach ($personalDishes as $pd) {
            $dish     = $pd->dish;
            $mealType = $pd->mealType;
            if (!$dish) continue;

            $baseW     = (float)($dish->base_weight_g ?? 0);
            $totalKcal = (float)($dish->total_kcal ?? 0);
            $kcalPer100 = ($baseW > 0 && $totalKcal > 0) ? ($totalKcal / $baseW) * 100.0 : 0;

            if ($pd->weight_grams) {
                $weight = (int)$pd->weight_grams;
            } else {
                $kcalForMeal = $count > 0 ? $targetKcal / $count : 0;
                $weight = ($kcalPer100 > 0)
                    ? (int)round(($kcalForMeal / $kcalPer100) * 100.0)
                    : (int)$baseW;
            }

            $dishKcal = $weight * $kcalPer100 / 100.0;
            $protPerG = $baseW > 0 ? (float)($dish->total_prot ?? 0) / $baseW : 0.0;
            $fatPerG  = $baseW > 0 ? (float)($dish->total_fat  ?? 0) / $baseW : 0.0;
            $carbPerG = $baseW > 0 ? (float)($dish->total_carb ?? 0) / $baseW : 0.0;

            $totals['kcal'] += $dishKcal;
            $totals['prot'] += $weight * $protPerG;
            $totals['fat']  += $weight * $fatPerG;
            $totals['carb'] += $weight * $carbPerG;

            $items[] = [
                'meal'         => $mealType?->name ?? '-',
                'meal_type_id' => (int)$pd->meal_type_id,
                'meal_sort'    => $mealType?->sort_order ?? 99,
                'dish_id'      => (int)$dish->id,
                'dish_name'    => $dish->name,
                'weight'       => $weight,
                'kcal'         => $dishKcal,
                'prot'         => $weight * $protPerG,
                'fat'          => $weight * $fatPerG,
                'carb'         => $weight * $carbPerG,
            ];
        }

        usort($items, fn($a, $b) => $a['meal_sort'] <=> $b['meal_sort']);

        return ['items' => $items, 'totals' => $totals];
    }

    private function buildDayPlan(Order $order, DailyMenu $menu): array
    {
        $targetKcal = (float) ($order->calories ?? 0);
        if ($targetKcal <= 0) {
            return ['items' => [], 'totals' => ['kcal' => 0, 'prot' => 0, 'fat' => 0, 'carb' => 0]];
        }

        $clientMealTypeIds = $order->client->mealTypes->pluck('id')->toArray();

        $availableItems = $menu->menuItems
            ->filter(fn($item) => $item->dish && in_array($item->meal_type_id, $clientMealTypeIds, true))
            ->sortBy(fn($item) => $item->mealType?->sort_order ?? 99)
            ->values();

        if ($availableItems->isEmpty()) {
            return ['items' => [], 'totals' => ['kcal' => 0, 'prot' => 0, 'fat' => 0, 'carb' => 0]];
        }

        $allowedSortOrders = \App\Models\MealPlan::getAllowedSortOrders((int) $targetKcal);
        $selectedItems = $availableItems->filter(
            fn ($item) => in_array($item->mealType?->sort_order, $allowedSortOrders)
        )->values();
        $byMeal = $selectedItems->groupBy('meal_type_id');

        // Нормалізація: якщо вибрані страви мають відсотки що не дають 100%
        // (наприклад, 3 страви по 20% = 60%), нормалізуємо до 100%
        $rawPct = [];
        foreach ($byMeal as $mealTypeId => $items) {
            $fi = $items->first();
            $rawPct[$mealTypeId] = $fi->custom_energy_percent !== null
                ? (float) $fi->custom_energy_percent
                : (float) ($fi->mealType?->energy_percent ?? 0);
        }
        $totalPct = array_sum($rawPct);
        $normFactor = ($totalPct > 0.5 && abs($totalPct - 100) > 0.5) ? (100.0 / $totalPct) : 1.0;

        $totals = ['kcal' => 0.0, 'prot' => 0.0, 'fat' => 0.0, 'carb' => 0.0];
        $resultItems = [];

        foreach ($byMeal as $mealTypeId => $items) {
            $firstItem = $items->first();
            $mealType  = $firstItem->mealType;

            $p = ($rawPct[$mealTypeId] ?? 0) * $normFactor;

            $mealKcal = ($p > 0)
                ? $targetKcal * ($p / 100.0)
                : $targetKcal * (1.0 / max(1, $byMeal->count()));

            $kcalPerDish = $mealKcal / max(1, $items->count());

            foreach ($items as $item) {
                $dish = $item->dish;
                if (! $dish) continue;

                $baseW     = (float) ($dish->base_weight_g ?? 0);
                $totalKcal = (float) ($dish->total_kcal ?? 0);
                $kcalPer100 = ($baseW > 0 && $totalKcal > 0) ? ($totalKcal / $baseW) * 100.0 : 0;

                $weight = $kcalPer100 > 0 ? (int) round(($kcalPerDish / $kcalPer100) * 100.0) : 0;

                $protPerG = $baseW > 0 ? (float) ($dish->total_prot ?? 0) / $baseW : 0.0;
                $fatPerG  = $baseW > 0 ? (float) ($dish->total_fat  ?? 0) / $baseW : 0.0;
                $carbPerG = $baseW > 0 ? (float) ($dish->total_carb ?? 0) / $baseW : 0.0;

                $dishKcal = $weight * $kcalPer100 / 100.0;

                $totals['kcal'] += $dishKcal;
                $totals['prot'] += $weight * $protPerG;
                $totals['fat']  += $weight * $fatPerG;
                $totals['carb'] += $weight * $carbPerG;

                $resultItems[] = [
                    'meal'         => $mealType?->name ?? '-',
                    'meal_type_id' => (int) $mealTypeId,
                    'meal_sort'    => $mealType?->sort_order ?? 99,
                    'dish_id'      => (int) $dish->id,
                    'dish_name'    => $dish->name,
                    'weight'       => $weight,
                    'kcal'         => $dishKcal,
                    'prot'         => $weight * $protPerG,
                    'fat'          => $weight * $fatPerG,
                    'carb'         => $weight * $carbPerG,
                ];
            }
        }

        usort($resultItems, fn($a, $b) => $a['meal_sort'] <=> $b['meal_sort']);

        return ['items' => $resultItems, 'totals' => $totals];
    }

    // Повертає список інгредієнтів з урахуванням замін та виключень клієнта
    private function getIngredientsWithReplacements($dish, float $k, Order $order, int $rootDishId, float $subRatio = 1.0): array
    {
        $list = [];
        if (! $dish || ! $dish->dishIngredients) return $list;

        foreach ($dish->dishIngredients as $di) {
            $currentK = $k * $subRatio;
            $type = mb_strtolower(trim((string) ($di->type ?? '')));

            if (in_array($type, ['product', 'продукт'], true) && $di->ingredient) {
                $ing       = $di->ingredient;
                $netWeight = round((float) ($di->net_weight_g ?? 0) * $currentK, 1);

                // Заміна інгредієнта для цієї страви
                $replacement = $order->replacements
                    ->where('dish_id', $rootDishId)
                    ->where('original_product_id', $ing->id)
                    ->first();

                $isExcluded = $order->client->ingredientExclusions->contains('id', $ing->id);

                if ($replacement && $replacement->replacementProduct) {
                    $list[] = [
                        'name'          => $replacement->replacementProduct->name,
                        'original_name' => $ing->name,
                        'net_weight'    => $netWeight,
                        'is_replaced'   => true,
                    ];
                } elseif (! $isExcluded) {
                    $list[] = [
                        'name'          => $ing->name,
                        'original_name' => null,
                        'net_weight'    => $netWeight,
                        'is_replaced'   => false,
                    ];
                }
                // Виключені інгредієнти клієнту не показуємо
            } elseif (in_array($type, ['pf', 'пф', 'напівфабрикат', 'п/ф', 'н/ф'], true) && $di->childDish) {
                $pfTotals = $di->childDish->calculated_totals;
                $pfOutput = (float) ($pfTotals['output_weight'] ?? 0);
                if ($pfOutput <= 0) continue;
                $pfRatio = ((float) ($di->net_weight_g ?? 0) * $currentK) / $pfOutput;
                $list = array_merge(
                    $list,
                    $this->getIngredientsWithReplacements($di->childDish, 1.0, $order, $rootDishId, $pfRatio)
                );
            }
        }

        return $list;
    }

    // Збирає всі алергени страви рекурсивно
    private function collectAllergens($dish, array $seen = []): array
    {
        $allergens = [];
        if (! $dish || ! $dish->dishIngredients) return $allergens;

        foreach ($dish->dishIngredients as $di) {
            if ($di->ingredient) {
                foreach ($di->ingredient->allergens ?? [] as $allergen) {
                    $allergens[$allergen->name] = true;
                }
            }
            if ($di->childDish && ! in_array($di->childDish->id, $seen)) {
                $seen[] = $di->childDish->id;
                foreach ($this->collectAllergens($di->childDish, $seen) as $name => $_) {
                    $allergens[$name] = true;
                }
            }
        }

        return array_keys($allergens);
    }

}
