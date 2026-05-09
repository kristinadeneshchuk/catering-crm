<?php

namespace App\Traits;

use App\Models\DailyMenu;
use App\Models\Order;
use App\Support\DailyWeightMultiplier;
use Illuminate\Support\Collection;

trait CalculatesOrderPlan
{
    private function calculateOrderPlan(Order $order, DailyMenu $menu, ?string $date = null): array
    {
        $empty = ['items' => [], 'totals' => ['kcal' => 0, 'prot' => 0, 'fat' => 0, 'carb' => 0]];
        $weightMultiplier = DailyWeightMultiplier::for($date);

        // Індивідуальний клієнт — беремо персональне меню на дату
        if ($date && $order->menu_type === 'individual') {
            $personalDishes = $order->personalDishes()
                ->where('date', $date)
                ->with(['dish', 'mealType'])
                ->get();

            if ($personalDishes->isNotEmpty()) {
                return $this->buildPlanFromPersonal($personalDishes, $order, $weightMultiplier);
            }
        }

        $targetKcal = (float)($order->calories ?? 0);
        if ($targetKcal <= 0) return $empty;

        $clientMealTypeIds = $order->client?->mealTypes?->pluck('id')->toArray() ?? [];

        $availableItems = $menu->menuItems
            ->filter(fn($item) => $item->dish && in_array($item->meal_type_id, $clientMealTypeIds, true))
            ->sortBy(fn($item) => $item->mealType?->sort_order ?? 99)
            ->values();

        if ($availableItems->isEmpty()) return $empty;

        $allowedSortOrders = \App\Models\MealPlan::getAllowedSortOrders((int)$targetKcal);
        $selected = $availableItems->filter(
            fn($item) => in_array($item->mealType?->sort_order, $allowedSortOrders)
        )->values();

        if ($selected->isEmpty()) return $empty;

        $byMeal = $selected->groupBy('meal_type_id');

        // Нормалізація відсотків до 100% для вибраних прийомів їжі
        $rawPct = [];
        foreach ($byMeal as $mealTypeId => $items) {
            $fi = $items->first();
            $rawPct[$mealTypeId] = $fi->custom_energy_percent !== null
                ? (float)$fi->custom_energy_percent
                : (float)($fi->mealType?->energy_percent ?? 0);
        }
        $totalPct   = array_sum($rawPct);
        $normFactor = ($totalPct > 0.5 && abs($totalPct - 100) > 0.5) ? (100.0 / $totalPct) : 1.0;

        $totals   = ['kcal' => 0.0, 'prot' => 0.0, 'fat' => 0.0, 'carb' => 0.0];
        $itemsOut = [];

        foreach ($byMeal as $mealTypeId => $items) {
            $firstItem = $items->first();
            $mealType  = $firstItem->mealType;

            $p        = ($rawPct[$mealTypeId] ?? 0) * $normFactor;
            $mealKcal = ($p > 0)
                ? $targetKcal * ($p / 100.0)
                : $targetKcal * (1.0 / max(1, $byMeal->count()));

            $kcalPerDish = $mealKcal / max(1, $items->count());

            foreach ($items as $item) {
                $dish = $item->dish;
                if (!$dish) continue;

                $kcalPer100 = $this->dishKcalPer100g($dish);
                $weight     = ($kcalPer100 > 0)
                    ? (int)round(($kcalPerDish / $kcalPer100) * 100.0 * $weightMultiplier)
                    : 0;

                $dt    = $dish->calculated_totals;
                $outW  = (float)($dt['output_weight'] ?? ($dish->base_weight_g ?? 0));
                $outW  = $outW > 0 ? $outW : 1.0;

                $totals['kcal'] += $weight * $kcalPer100 / 100.0;
                $totals['prot'] += $weight * ((float)($dt['prot'] ?? 0) / $outW);
                $totals['fat']  += $weight * ((float)($dt['fat']  ?? 0) / $outW);
                $totals['carb'] += $weight * ((float)($dt['carb'] ?? 0) / $outW);

                $itemsOut[] = [
                    'dish_id'      => (int)$dish->id,
                    'meal_type_id' => (int)$mealTypeId,
                    'weight'       => $weight,
                    'meal'         => $mealType?->name ?? '-',
                    'dish'         => $dish->name,
                ];
            }
        }

        // Сортуємо за sort_order прийому їжі
        usort($itemsOut, function ($a, $b) use ($menu) {
            $aSort = $menu->menuItems->firstWhere('meal_type_id', $a['meal_type_id'])?->mealType?->sort_order ?? 99;
            $bSort = $menu->menuItems->firstWhere('meal_type_id', $b['meal_type_id'])?->mealType?->sort_order ?? 99;
            return $aSort <=> $bSort;
        });

        return ['items' => $itemsOut, 'totals' => $totals];
    }

    // Будуємо план з персонального меню (для індивідуальних клієнтів)
    private function buildPlanFromPersonal(Collection $personalDishes, Order $order, float $weightMultiplier = 1.0): array
    {
        $targetKcal = (float)($order->calories ?? 0);
        $count      = $personalDishes->count();
        $totals     = ['kcal' => 0.0, 'prot' => 0.0, 'fat' => 0.0, 'carb' => 0.0];
        $itemsOut   = [];

        foreach ($personalDishes as $pd) {
            $dish     = $pd->dish;
            $mealType = $pd->mealType;
            if (!$dish) continue;

            $kcalPer100 = $this->dishKcalPer100g($dish);

            // Якщо вага задана вручну — використовуємо її, інакше розраховуємо по калоріям
            if ($pd->weight_grams) {
                $weight = (int)round((int)$pd->weight_grams * $weightMultiplier);
            } else {
                $kcalForMeal = $count > 0 ? $targetKcal / $count : 0;
                $weight      = ($kcalPer100 > 0)
                    ? (int)round(($kcalForMeal / $kcalPer100) * 100.0 * $weightMultiplier)
                    : 0;
            }

            $dt   = $dish->calculated_totals;
            $outW = (float)($dt['output_weight'] ?? ($dish->base_weight_g ?? 0));
            $outW = $outW > 0 ? $outW : 1.0;

            $totals['kcal'] += $weight * $kcalPer100 / 100.0;
            $totals['prot'] += $weight * ((float)($dt['prot'] ?? 0) / $outW);
            $totals['fat']  += $weight * ((float)($dt['fat']  ?? 0) / $outW);
            $totals['carb'] += $weight * ((float)($dt['carb'] ?? 0) / $outW);

            $itemsOut[] = [
                'dish_id'      => (int)$dish->id,
                'meal_type_id' => (int)$pd->meal_type_id,
                'weight'       => $weight,
                'meal'         => $mealType?->name ?? '-',
                'dish'         => $dish->name,
            ];
        }

        return ['items' => $itemsOut, 'totals' => $totals];
    }

    private function dishKcalPer100g($dish): float
    {
        $baseW     = (float)($dish->base_weight_g ?? 0);
        $totalKcal = (float)($dish->total_kcal ?? 0);
        if ($baseW <= 0 || $totalKcal <= 0) return 0.0;
        return ($totalKcal / $baseW) * 100.0;
    }
}
