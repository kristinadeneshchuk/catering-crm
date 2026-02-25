<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Carbon\Carbon;
use App\Models\OrderDay;
use App\Models\Setting;
use App\Models\DailyMenu;
use App\Models\Ingredient;
use Illuminate\Support\Collection;

class AnalyticsController extends Controller
{
    public function index(Request $request)
    {
        // 1. Беремо дати з інпутів
        $startDate = $request->input('start_date', Carbon::now()->startOfMonth()->format('Y-m-d'));
        $endDate = $request->input('end_date', Carbon::now()->format('Y-m-d'));

        $start = Carbon::parse($startDate);
        $end = Carbon::parse($endDate);

        // Захист від занадто великих діапазонів (макс 60 днів)
        if ($start->diffInDays($end) > 60) {
            $end = $start->copy()->addDays(60);
            $endDate = $end->format('Y-m-d');
        }

        // Формуємо масив дат
        $dates = [];
        for ($date = $start->copy(); $date->lte($end); $date->addDay()) {
            $dates[$date->format('Y-m-d')] = $date->format('d.m');
        }

        // 🔥 2. Завантажуємо всі дні з повними зв'язками для аналізу собівартості та замін
        $validDays = OrderDay::whereBetween('date', [$startDate, $endDate])
            ->with([
                'order.client.mealTypes',
                'order.client.ingredientExclusions',
                'order.client.dishExclusions',
                'order.replacements.replacementProduct',
                'order.replacements.replacementDish.dishIngredients.ingredient'
            ])
            ->get();

        $groupedDays = $validDays->groupBy(function ($item) {
            return Carbon::parse($item->date)->format('Y-m-d');
        });

        // 3. Завантажуємо налаштування циклічного меню та базу інгредієнтів
        $cycleDays = (int) Setting::where('key', 'menu_cycle_days')->value('value') ?: 24;
        $startDateStr = Setting::where('key', 'menu_cycle_start_date')->value('value') ?: '2025-01-01';
        $anchorDate = Carbon::parse($startDateStr);

        $allMenus = DailyMenu::with([
            'menuItems.dish.dishIngredients.ingredient',
            'menuItems.dish.dishIngredients.childDish.dishIngredients.ingredient',
            'menuItems.mealType'
        ])->get()->keyBy('day_number');
        
        $allIngredients = Ingredient::all()->keyBy('id');

        // 4. Масиви для фінальних результатів
        $rationsCount = [];
        $totalRations = 0;
        
        $revenueCount = [];
        $totalRevenue = 0;

        $foodCostCount = [];
        $totalFoodCost = 0;

        foreach ($dates as $ymd => $dm) {
            $days = $groupedDays->get($ymd, collect());
            
            // КІЛЬКІСТЬ РАЦІОНІВ
            $count = $days->count();
            $rationsCount[$ymd] = $count;
            $totalRations += $count;

            // ГРОШІ (Виручка та Собівартість)
            $dailyRevenue = 0;
            $dailyFoodCost = 0;

            if ($count > 0) {
                // Визначаємо день циклічного меню
                $diff = abs(Carbon::parse($ymd)->diffInDays($anchorDate));
                $dayNum = ($diff % $cycleDays) + 1;
                $menu = $allMenus->get($dayNum);

                foreach ($days as $orderDay) {
                    $order = $orderDay->order;
                    if (!$order) continue;

                    // А. Виручка (Метод нарахування)
                    $duration = max(1, (int)$order->duration); 
                    $dailyRevenue += ((float)$order->total_price / $duration);

                    // Б. Собівартість (До грама, як у плані виробництва)
                    if ($menu) {
                        $orderCost = $this->calculateOrderFoodCost($order, $menu, $allIngredients);
                        $dailyFoodCost += $orderCost;
                    }
                }
            }
            
            $revenueCount[$ymd] = round($dailyRevenue);
            $totalRevenue += round($dailyRevenue);

            $foodCostCount[$ymd] = round($dailyFoodCost);
            $totalFoodCost += round($dailyFoodCost);
        }

        return view('analytics.index', compact(
            'dates', 
            'startDate', 
            'endDate',
            'rationsCount',
            'totalRations',
            'revenueCount',
            'totalRevenue',
            'foodCostCount',
            'totalFoodCost'
        ));
    }

    // =========================================================
    // 🔥 ДВИГУН РОЗРАХУНКУ СОБІВАРТОСТІ (КОПІЯ З ПЛАНУ ВИРОБНИЦТВА)
    // =========================================================

    private function calculateOrderFoodCost($order, $menu, Collection $allIngredients): float
    {
        $targetKcal = (float)($order->calories ?? 0);
        if ($targetKcal <= 0) return 0.0;

        $clientMealTypeIds = $order->client?->mealTypes?->pluck('id')->toArray() ?? [];

        $availableItems = $menu->menuItems
            ->filter(fn ($item) => $item->dish && in_array($item->meal_type_id, $clientMealTypeIds, true))
            ->sortBy(fn ($item) => $item->mealType?->sort_order ?? 99)
            ->values();

        if ($availableItems->isEmpty()) return 0.0;

        $expectedDishes = 5;
        if ($targetKcal < 1200) $expectedDishes = 3;
        elseif ($targetKcal < 1500) $expectedDishes = 4;

        $selectedItems = $availableItems->take($expectedDishes);
        if ($selectedItems->isEmpty()) return 0.0;

        $byMeal = $selectedItems->groupBy('meal_type_id');
        $percentSum = 0.0;
        foreach ($byMeal as $mealTypeId => $items) {
            $percentSum += (float)($items->first()->mealType?->energy_percent ?? 0);
        }
        if ($percentSum <= 0) $percentSum = 100.0;

        $totalOrderCost = 0.0;

        foreach ($byMeal as $mealTypeId => $items) {
            $mealType = $items->first()->mealType;
            $p = (float)($mealType?->energy_percent ?? 0);

            $mealKcal = ($p > 0)
                ? $targetKcal * ($p / $percentSum)
                : $targetKcal * (1.0 / max(1, $byMeal->count()));

            $countInMeal = max(1, $items->count());
            $kcalPerDish = $mealKcal / $countInMeal;

            foreach ($items as $mi) {
                $dish = $mi->dish;
                if (!$dish) continue;

                // Перевіряємо заміну/виключення цілої страви
                if ($order->client && $order->client->dishExclusions->contains('id', $dish->id)) {
                    $dishRep = $order->replacements->where('dish_id', $dish->id)->whereNull('original_product_id')->first();
                    if ($dishRep && $dishRep->replacementDish) {
                        $dish = $dishRep->replacementDish;
                    } else {
                        continue; 
                    }
                }

                $baseW = (float)($dish->base_weight_g ?? 0);
                $totalKcal = (float)($dish->total_kcal ?? 0);
                $kcalPer100 = ($baseW > 0 && $totalKcal > 0) ? ($totalKcal / $baseW) * 100.0 : 0;
                
                $plannedWeight = ($kcalPer100 > 0) ? ($kcalPerDish / $kcalPer100) * 100.0 : 0;
                $dishScale = ($baseW > 0) ? ($plannedWeight / $baseW) : 0.0;

                // Збираємо інгредієнти та їхню вагу БРУТТО
                $components = $this->getHierarchicalIngredients($dish, $dishScale, 1.0, $dish->id, $order);
                
                // Рахуємо вартість
                foreach ($components as $comp) {
                    $totalOrderCost += $this->calculateComponentCost($comp, $allIngredients);
                }
            }
        }

        return $totalOrderCost;
    }

    private function getHierarchicalIngredients($dish, float $scale, float $subRatio, $rootDishId, $order): array
    {
        $components = [];
        if (!$dish || !$dish->dishIngredients) return $components;

        foreach ($dish->dishIngredients as $di) {
            $currentK = $scale * $subRatio;
            $type = mb_strtolower(trim((string)($di->type ?? '')));
            $nettoTotalRaw = (float)($di->net_weight_g ?? 0) * $currentK;

            $isProduct = in_array($type, ['product', 'продукт'], true);
            $isPf = in_array($type, ['pf', 'напівфабрикат', 'п/ф', 'н/ф'], true);

            $finalProductId = $di->ingredient_id;
            $finalYield = $di->ingredient ? (float)($di->ingredient->yield_percent ?: 100) : 100;
            $isExcluded = false;

            if ($isProduct && $di->ingredient && $order->client) {
                if ($order->client->ingredientExclusions->contains('id', $di->ingredient_id)) {
                    $rep = $order->replacements->where('dish_id', $rootDishId)->where('original_product_id', $di->ingredient_id)->first();
                    if ($rep && $rep->replacementProduct) {
                        $finalProductId = $rep->replacementProduct->id;
                        $finalYield = (float)($rep->replacementProduct->yield_percent ?: 100);
                    } else {
                        $isExcluded = true;
                    }
                }
            }

            if ($isProduct && !$isExcluded && $finalProductId) {
                if ($finalYield <= 0) $finalYield = 100;
                $brutto = ($nettoTotalRaw * 100) / $finalYield;

                $components[] = [
                    'type' => 'product',
                    'product_id' => $finalProductId,
                    'weight_brutto' => $brutto,
                ];
            }

            if ($isPf && $di->childDish) {
                $pfTotals = $di->childDish->calculated_totals;
                $pfOutput = (float)($pfTotals['output_weight'] ?? 0);
                if ($pfOutput > 0) {
                    $pfRatio = ((float)($di->net_weight_g ?? 0)) / $pfOutput;
                    $subIngredients = $this->getHierarchicalIngredients($di->childDish, $scale, ($pfRatio * $subRatio), $rootDishId, $order);
                    $components = array_merge($components, $subIngredients);
                }
            }
        }
        return $components;
    }

    private function calculateComponentCost(array $component, Collection $allIngredients): float
    {
        if ($component['type'] !== 'product') return 0.0;

        $ingredient = $allIngredients->get($component['product_id']);
        if (!$ingredient) return 0.0;

        $weightGrams = (float)($component['weight_brutto'] ?? 0);
        $pricePerKg = (float)$ingredient->average_price; 

        // Переводимо грами в кілограми і множимо на ціну за кг
        return ($weightGrams / 1000) * $pricePerKg;
    }
}