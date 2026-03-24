<?php

namespace App\Services;

use Illuminate\Support\Collection;

class FoodCostService
{
    public function calculateOrderFoodCost($order, $menu, Collection $allIngredients): float
    {
        $targetKcal = (float)($order->calories ?? 0);
        if ($targetKcal <= 0) return 0.0;

        $clientMealTypeIds = $order->client?->mealTypes?->pluck('id')->toArray() ?? [];

        $availableItems = $menu->menuItems
            ->filter(fn ($item) => $item->dish && in_array($item->meal_type_id, $clientMealTypeIds, true))
            ->sortBy(fn ($item) => $item->mealType?->sort_order ?? 99)
            ->values();

        if ($availableItems->isEmpty()) return 0.0;

        $expectedDishes = ($targetKcal < 1200) ? 3 : (($targetKcal < 1500) ? 4 : 5);

        $selectedItems = $availableItems->take($expectedDishes);
        if ($selectedItems->isEmpty()) return 0.0;

        $byMeal = $selectedItems->groupBy('meal_type_id');

        $totalOrderCost = 0.0;

        foreach ($byMeal as $mealTypeId => $items) {
            $mealType = $items->first()->mealType;
            $p = (float)($mealType?->energy_percent ?? 0);

            $mealKcal = ($p > 0)
                ? $targetKcal * ($p / 100.0)
                : $targetKcal * (1.0 / max(1, $byMeal->count()));

            $countInMeal = max(1, $items->count());
            $kcalPerDish = $mealKcal / $countInMeal;

            foreach ($items as $mi) {
                $dish = $mi->dish;
                if (!$dish) continue;

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

                $components = $this->getHierarchicalIngredients($dish, $dishScale, 1.0, $dish->id, $order);
                
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

        return ($weightGrams / 1000) * $pricePerKg;
    }
}