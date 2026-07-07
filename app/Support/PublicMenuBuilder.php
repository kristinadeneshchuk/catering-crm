<?php

namespace App\Support;

use App\Models\DailyMenu;
use App\Models\MealPlan;

/**
 * Рахує граммовку та КБЖУ денного меню під конкретний калораж — для публічної
 * сторінки «Меню на сьогодні» (постійне посилання, яке менеджери шлють клієнтам).
 *
 * Формула повторює ClientMenuController::buildDayPlan(), але БЕЗ прив'язки до
 * замовлення/клієнта: показуємо повне меню дня, а не персональне.
 *  - цільові калорії задаються ззовні (стандартні калоражі 900…3400);
 *  - набір прийомів їжі під калораж береться з MealPlan (низькі калоражі — менше прийомів);
 *  - вага страви = (ккал на страву / ккал у 100 г) × 100 × денний множник.
 */
class PublicMenuBuilder
{
    public static function build(DailyMenu $menu, int $targetKcal, ?string $date = null): array
    {
        $empty = ['items' => [], 'totals' => ['kcal' => 0, 'prot' => 0, 'fat' => 0, 'carb' => 0]];
        if ($targetKcal <= 0) {
            return $empty;
        }

        $weightMultiplier = DailyWeightMultiplier::for($date);

        $availableItems = $menu->menuItems
            ->filter(fn ($item) => $item->dish)
            ->sortBy(fn ($item) => $item->mealType?->sort_order ?? 99)
            ->values();

        if ($availableItems->isEmpty()) {
            return $empty;
        }

        // Які прийоми їжі входять у цей калораж (як у персональному меню).
        $allowedSortOrders = MealPlan::getAllowedSortOrders($targetKcal);
        $selectedItems = $availableItems->filter(
            fn ($item) => in_array($item->mealType?->sort_order, $allowedSortOrders)
        )->values();

        $byMeal = $selectedItems->groupBy('meal_type_id');
        if ($byMeal->isEmpty()) {
            return $empty;
        }

        // Розкладка калорій по прийомах (energy_percent, з нормалізацією до 100%).
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
            $mealType = $items->first()->mealType;

            $p = ($rawPct[$mealTypeId] ?? 0) * $normFactor;
            $mealKcal = ($p > 0)
                ? $targetKcal * ($p / 100.0)
                : $targetKcal * (1.0 / max(1, $byMeal->count()));
            $kcalPerDish = $mealKcal / max(1, $items->count());

            foreach ($items as $item) {
                $dish = $item->dish;
                if (! $dish) continue;

                $baseW      = (float) ($dish->base_weight_g ?? 0);
                $totalKcal  = (float) ($dish->total_kcal ?? 0);
                $kcalPer100 = ($baseW > 0 && $totalKcal > 0) ? ($totalKcal / $baseW) * 100.0 : 0;

                $weight = $kcalPer100 > 0
                    ? (int) round(($kcalPerDish / $kcalPer100) * 100.0 * $weightMultiplier)
                    : 0;

                $protPerG = $baseW > 0 ? (float) ($dish->total_prot ?? 0) / $baseW : 0.0;
                $fatPerG  = $baseW > 0 ? (float) ($dish->total_fat  ?? 0) / $baseW : 0.0;
                $carbPerG = $baseW > 0 ? (float) ($dish->total_carb ?? 0) / $baseW : 0.0;

                $dishKcal = $weight * $kcalPer100 / 100.0;

                $totals['kcal'] += $dishKcal;
                $totals['prot'] += $weight * $protPerG;
                $totals['fat']  += $weight * $fatPerG;
                $totals['carb'] += $weight * $carbPerG;

                $resultItems[] = [
                    'meal'      => $mealType?->name ?? '-',
                    'meal_sort' => $mealType?->sort_order ?? 99,
                    'dish_name' => $dish->name,
                    'weight'    => $weight,
                    'kcal'      => $dishKcal,
                    'prot'      => $weight * $protPerG,
                    'fat'       => $weight * $fatPerG,
                    'carb'      => $weight * $carbPerG,
                ];
            }
        }

        usort($resultItems, fn ($a, $b) => $a['meal_sort'] <=> $b['meal_sort']);

        return ['items' => $resultItems, 'totals' => $totals];
    }
}
