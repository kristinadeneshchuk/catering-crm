<?php

namespace App\Services;

use App\Models\MealPlan;
use Illuminate\Support\Collection;

class FoodCostService
{
    /**
     * Одна страва — один обʼєкт на запит. Інакше замінні страви й напівфабрикати
     * приходять окремими екземплярами для кожного замовлення, і їхній склад
     * та КБЖВ щоразу тягнуться з бази (десятки тисяч запитів в аналітиці за місяць).
     */
    private array $dishes = [];

    private array $sortOrders = [];

    private array $unitCost = [];

    private array $productIdsCache = [];

    /** Усі продукти страви разом із напівфабрикатами — як ключі масиву. */
    private function productIds($dish): array
    {
        if (isset($this->productIdsCache[$dish->id])) return $this->productIdsCache[$dish->id];

        $ids = [];
        foreach ($dish->dishIngredients as $di) {
            $type = mb_strtolower(trim((string)($di->type ?? '')));
            if (in_array($type, ['product', 'продукт'], true) && $di->ingredient_id) {
                $ids[$di->ingredient_id] = true;
            }
            $child = in_array($type, ['pf', 'напівфабрикат', 'п/ф', 'н/ф'], true) ? $this->canonicalDish($di->childDish) : null;
            if ($child && $child->id !== $dish->id) {
                $ids += $this->productIds($child);
            }
        }

        return $this->productIdsCache[$dish->id] = $ids;
    }

    private function canonicalDish($dish)
    {
        if (!$dish) return $dish;

        return $this->dishes[$dish->id] ??= $dish->loadMissing('dishIngredients.ingredient', 'dishIngredients.childDish');
    }

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

        // Кількість страв визначається централізовано через "Плани харчування"
        $allowedSortOrders = $this->sortOrders[(int) $targetKcal] ??= MealPlan::getAllowedSortOrders((int)$targetKcal);
        $selectedItems = $availableItems->filter(
            fn ($item) => in_array($item->mealType?->sort_order, $allowedSortOrders)
        )->values();
        if ($selectedItems->isEmpty()) return 0.0;

        $byMeal = $selectedItems->groupBy('meal_type_id');

        // Нормалізація відсотків до 100% для вибраних страв
        $rawPct = [];
        foreach ($byMeal as $mealTypeId => $items) {
            $fi = $items->first();
            $rawPct[$mealTypeId] = $fi->custom_energy_percent !== null
                ? (float) $fi->custom_energy_percent
                : (float) ($fi->mealType?->energy_percent ?? 0);
        }
        $totalPct = array_sum($rawPct);
        $normFactor = ($totalPct > 0.5 && abs($totalPct - 100) > 0.5) ? (100.0 / $totalPct) : 1.0;

        $totalOrderCost = 0.0;
        // Виключення збираються з клієнта, наборів і замовлення — раз на замовлення,
        // а не на кожен інгредієнт кожної страви.
        $excludedIds = $order->client ? array_flip($order->effectiveExcludedIngredientIds()) : [];

        foreach ($byMeal as $mealTypeId => $items) {
            $mealType = $items->first()->mealType;
            $p = ($rawPct[$mealTypeId] ?? 0) * $normFactor;

            $mealKcal = ($p > 0)
                ? $targetKcal * ($p / 100.0)
                : $targetKcal * (1.0 / max(1, $byMeal->count()));

            $countInMeal = max(1, $items->count());
            $kcalPerDish = $mealKcal / $countInMeal;

            foreach ($items as $mi) {
                $dish = $this->canonicalDish($mi->dish);
                if (!$dish) continue;

                if ($order->client && $order->client->dishExclusions->contains('id', $dish->id)) {
                    $dishRep = $order->replacements->where('dish_id', $dish->id)->whereNull('original_product_id')->first();
                    if ($dishRep && $dishRep->replacementDish) {
                        $dish = $this->canonicalDish($dishRep->replacementDish);
                    } else {
                        continue; 
                    }
                }

                $baseW = (float)($dish->base_weight_g ?? 0);
                $totalKcal = (float)($dish->total_kcal ?? 0);
                $kcalPer100 = ($baseW > 0 && $totalKcal > 0) ? ($totalKcal / $baseW) * 100.0 : 0;
                
                $plannedWeight = ($kcalPer100 > 0) ? ($kcalPerDish / $kcalPer100) * 100.0 : 0;
                $dishScale = ($baseW > 0) ? ($plannedWeight / $baseW) : 0.0;

                // Без виключених продуктів вартість страви лінійна від масштабу порції:
                // рахуємо одиницю страви раз на запит, а не розгортаємо техкарту для кожного клієнта.
                if (!$excludedIds || !array_intersect_key($this->productIds($dish), $excludedIds)) {
                    $totalOrderCost += $dishScale * ($this->unitCost[$dish->id] ??= array_sum(array_map(
                        fn ($comp) => $this->calculateComponentCost($comp, $allIngredients),
                        $this->getHierarchicalIngredients($dish, 1.0, 1.0, $dish->id, $order)
                    )));
                    continue;
                }

                $components = $this->getHierarchicalIngredients($dish, $dishScale, 1.0, $dish->id, $order, $excludedIds);
                
                foreach ($components as $comp) {
                    $totalOrderCost += $this->calculateComponentCost($comp, $allIngredients);
                }
            }
        }

        return $totalOrderCost;
    }

    private function getHierarchicalIngredients($dish, float $scale, float $subRatio, $rootDishId, $order, array $excludedIds = []): array
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
                if (isset($excludedIds[$di->ingredient_id])) {
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

            $childDish = $isPf ? $this->canonicalDish($di->childDish) : null;
            if ($childDish) {
                $pfTotals = $childDish->calculated_totals;
                $pfOutput = (float)($pfTotals['output_weight'] ?? 0);
                if ($pfOutput > 0) {
                    $pfRatio = ((float)($di->net_weight_g ?? 0)) / $pfOutput;
                    $subIngredients = $this->getHierarchicalIngredients($childDish, $scale, ($pfRatio * $subRatio), $rootDishId, $order, $excludedIds);
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