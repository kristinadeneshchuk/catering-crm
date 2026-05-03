<?php

namespace App\Http\Controllers;

use App\Models\DailyMenu;
use App\Models\DishRating;
use App\Models\KitchenNotification;
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
        $maxDate = $today->copy()->addDays(2);
        $date = $request->input('date')
            ? Carbon::parse($request->input('date'))->startOfDay()
            : $today;

        if ($date->greaterThan($maxDate)) {
            $date = $today;
        }

        $relations = [
            'client.mealTypes',
            'client.ingredientExclusions',
            'client.dishExclusions',
            'menuPlan',
            'replacements.originalProduct',
            'replacements.replacementProduct',
            'replacements.replacementDish.dishIngredients.ingredient',
            'tariff',
            'projectData',
        ];

        // Спочатку перевіряємо чи є OrderDay у замовленні по токену
        // (клієнт може мати кілька замовлень з різними калоріями)
        $tokenOrderHasDay = $order->orderDays()->where('date', $date->format('Y-m-d'))->exists();

        if ($tokenOrderHasDay) {
            $activeOrder = $order->load($relations);
        } else {
            $activeOrder = Order::where('client_id', $client->id)
                ->whereHas('orderDays', fn($q) => $q->where('date', $date->format('Y-m-d')))
                ->with($relations)
                ->first();
        }

        $items = [];
        $totals = ['kcal' => 0, 'prot' => 0, 'fat' => 0, 'carb' => 0];

        if ($activeOrder) {
            if ($activeOrder->menu_type === 'individual') {
                $result = $this->buildIndividualDayPlan($activeOrder, $date->format('Y-m-d'));
                $items  = $result['items'];
                $totals = $result['totals'];
            } else {
                [$menu] = $this->getMenuForDate($date, $activeOrder);
                if ($menu) {
                    $result = $this->buildDayPlan($activeOrder, $menu);
                    $items  = $result['items'];
                    $totals = $result['totals'];
                }
            }
        }

        $prevDate = $date->copy()->subDay();
        $nextDate = $date->copy()->addDay();

        $hasPrev = Order::where('client_id', $client->id)
            ->whereHas('orderDays', fn($q) => $q->where('date', $prevDate->format('Y-m-d')))
            ->exists();

        $hasNext = !$nextDate->greaterThan($maxDate) && Order::where('client_id', $client->id)
            ->whereHas('orderDays', fn($q) => $q->where('date', $nextDate->format('Y-m-d')))
            ->exists();

        // ── Рейтинги поточного дня ──
        $usedOrder      = $activeOrder ?? $order;
        $isToday        = $date->isToday();
        $rewardsEnabled = (bool)(int) Setting::where('key', 'rewards_enabled')->value('value');

        // Завантажуємо збережені оцінки тільки якщо сьогодні
        $todayRatings = [];
        if ($isToday && $usedOrder) {
            $todayRatings = DishRating::where('order_id', $usedOrder->id)
                ->where('date', $date->format('Y-m-d'))
                ->get()
                ->keyBy('dish_id')
                ->toArray();
        }

        // ── Прогрес (тільки якщо нагороди увімкнені) ──
        $progress = $rewardsEnabled ? $this->calculateProgress($usedOrder) : ['goal' => 0, 'completed' => 0, 'reward' => false, 'reward_given' => false];

        return view('menu.show', [
            'token'          => $token,
            'client'         => $client,
            'order'          => $usedOrder,
            'date'           => $date,
            'items'          => $items,
            'totals'         => $totals,
            'hasPrev'        => $hasPrev,
            'hasNext'        => $hasNext,
            'prevDate'       => $prevDate,
            'nextDate'       => $nextDate,
            'isToday'        => $isToday,
            'todayRatings'   => $todayRatings,
            'progress'       => $progress,
            'rewardsEnabled' => $rewardsEnabled,
        ]);
    }

    // =====================================================================
    // Зберегти/оновити рейтинг страви (AJAX)
    // =====================================================================
    public function rate(string $token, Request $request)
    {
        $request->validate([
            'dish_id' => 'required|integer|exists:dishes,id',
            'stars'   => 'required|integer|min:1|max:5',
            'comment' => 'nullable|string|max:500',
        ]);

        $order = Order::where('menu_token', $token)->firstOrFail();
        $today = now()->format('Y-m-d');

        DishRating::updateOrCreate(
            [
                'order_id' => $order->id,
                'dish_id'  => $request->dish_id,
                'date'     => $today,
            ],
            [
                'stars'   => $request->stars,
                'comment' => $request->comment,
            ]
        );

        // Перевіряємо чи розблокована нагорода (тільки якщо функція увімкнена)
        $rewardsEnabled     = (bool)(int) Setting::where('key', 'rewards_enabled')->value('value');
        $progress           = $rewardsEnabled ? $this->calculateProgress($order) : ['goal' => 0, 'completed' => 0, 'reward' => false, 'reward_given' => false];
        $rewardJustUnlocked = false;

        if ($rewardsEnabled && $progress['completed'] >= $progress['goal'] && $progress['goal'] > 0 && !$order->reward_unlocked) {
            $order->update(['reward_unlocked' => true]);
            $rewardJustUnlocked = true;

            // Сповіщення для адміна/менеджера
            KitchenNotification::create([
                'message'     => "🎁 Клієнт {$order->client->name} виконав умову рейтингу — {$progress['goal']} дн. оцінено. Потрібно видати нагороду.",
                'type'        => 'reward',
                'order_id'    => $order->id,
                'client_id'   => $order->client_id,
                'client_name' => $order->client->name,
            ]);
        }

        // Оновлюємо прогрес після збереження
        $progress = $this->calculateProgress($order->fresh());

        return response()->json([
            'ok'                  => true,
            'progress'            => $progress,
            'reward_just_unlocked' => $rewardJustUnlocked,
            'reward_unlocked'     => $order->fresh()->reward_unlocked,
        ]);
    }

    // =====================================================================
    // Розрахунок прогресу для замовлення
    // =====================================================================
    private function calculateProgress(Order $order): array
    {
        // Ціль: якщо замовлення > 5 днів — ціль 5, інакше — вся тривалість
        $goal = $order->duration > 5 ? 5 : max(1, (int) $order->duration);

        // Знаходимо дні де клієнт оцінив ВСІ страви
        // Спочатку беремо всі унікальні дати з оцінками
        $ratedDates = DishRating::where('order_id', $order->id)
            ->select('date')
            ->groupBy('date')
            ->pluck('date')
            ->toArray();

        $completedDays = 0;
        foreach ($ratedDates as $ratedDate) {
            // Рахуємо скільки страв мав клієнт в цей день
            $expectedCount = $this->getDishCountForDate($order, $ratedDate);
            // Рахуємо скільки оцінок є
            $ratedCount = DishRating::where('order_id', $order->id)
                ->where('date', $ratedDate)
                ->count();

            if ($expectedCount > 0 && $ratedCount >= $expectedCount) {
                $completedDays++;
            }
        }

        return [
            'goal'        => $goal,
            'completed'   => min($completedDays, $goal),
            'reward'      => $order->reward_unlocked,
            'reward_given' => $order->reward_given,
        ];
    }

    // Скільки страв у клієнта на конкретну дату
    private function getDishCountForDate(Order $order, string $date): int
    {
        if ($order->menu_type === 'individual') {
            return OrderDayDish::where('order_id', $order->id)
                ->where('date', $date)
                ->count();
        }

        // Для циклічного меню — рахуємо страви з меню що відповідають типам клієнта
        $dateObj = Carbon::parse($date);
        [$menu] = $this->getMenuForDate($dateObj, $order);
        if (!$menu) return 0;

        $clientMealTypeIds = $order->client->mealTypes->pluck('id')->toArray();
        $allowed = \App\Models\MealPlan::getAllowedSortOrders((int) $order->calories);

        return $menu->menuItems
            ->filter(fn($item) =>
                $item->dish &&
                in_array($item->meal_type_id, $clientMealTypeIds) &&
                in_array($item->mealType?->sort_order, $allowed)
            )
            ->count();
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
                'menuPlan',
                'replacements.originalProduct',
                'replacements.replacementProduct',
                'replacements.replacementDish.dishIngredients.ingredient',
            ])
            ->first() ?? $order->load([
                'client.mealTypes',
                'client.ingredientExclusions',
                'client.dishExclusions',
                'menuPlan',
                'replacements.originalProduct',
                'replacements.replacementProduct',
                'replacements.replacementDish.dishIngredients.ingredient',
            ]);

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

        [$menu] = $this->getMenuForDate($date, $activeOrder);
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

    /**
     * Меню на дату для конкретного замовлення (через його план меню).
     * Якщо $order не передано — fallback на дефолтний план (бек-сумісність).
     */
    private function getMenuForDate(Carbon $date, ?Order $order = null): array
    {
        $plan = $order?->effectiveMenuPlan() ?? \App\Models\MenuPlan::default();
        if (!$plan) return [null, 0];

        $globalDay = $plan->globalDayFor($date);

        $menu = DailyMenu::where('menu_plan_id', $plan->id)
            ->where('day_number', $globalDay)
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
