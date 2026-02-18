<?php

namespace App\Http\Controllers;

use App\Models\DailyMenu;
use App\Models\Order;
use App\Models\Setting;
use Illuminate\Http\Request;
use Carbon\Carbon;

class PrintController extends Controller
{
    public function manifest(Request $request)
    {
        $inputDate  = $request->input('date', now()->format('Y-m-d'));
        $targetDate = Carbon::parse($inputDate)->addDay()->format('Y-m-d');

        [$menu, $globalDay] = $this->getMenuForTargetDate($targetDate);

        if (!$menu) {
            return "❌ На День циклу №{$globalDay} меню ще не створено.";
        }

        $orders = Order::whereIn('status', ['new', 'active'])
            ->whereHas('orderDays', function ($query) use ($targetDate) {
                $query->where('date', $targetDate);
            })
            ->with(['client.mealTypes'])
            ->get();

        $manifests = [];

        foreach ($orders as $order) {
            $calc = $this->calculateOrderPlan($order, $menu);

            if (empty($calc['items'])) {
                continue;
            }

            $manifests[] = [
                'client_id'   => $order->client?->id ?? '---',
                'has_cutlery' => (bool) ($order->client?->has_cutlery ?? true),
                'project'     => $order->project,
                'client'      => $order->client?->name ?? 'Без імені',
                'address'     => $order->client?->address ?? 'Самовивіз',
                'calories'    => (int) $order->calories,
                'comment'     => $order->comment ?? $order->client?->production_comment,
                'items'       => $calc['items'],
                'date'        => $targetDate,
                'nutrition'   => [
                    'b' => round($calc['totals']['prot']),
                    'j' => round($calc['totals']['fat']),
                    'u' => round($calc['totals']['carb']),
                ],
            ];
        }

        usort($manifests, function ($a, $b) {
            if ($a['calories'] === $b['calories']) {
                return strcmp($a['client'], $b['client']);
            }
            return $a['calories'] <=> $b['calories'];
        });

        $date = $inputDate;
        return view('print.manifest', compact('manifests', 'date'));
    }

    public function stickers(Request $request)
    {
        $inputDate  = $request->input('date', now()->format('Y-m-d'));
        $targetDate = Carbon::parse($inputDate)->addDay()->format('Y-m-d');

        [$menu, $globalDay] = $this->getMenuForTargetDate($targetDate);

        if (!$menu) {
            return "❌ Меню не створено на завтра ({$targetDate}).";
        }

        $orders = Order::whereIn('status', ['new', 'active'])
            ->whereHas('orderDays', function ($query) use ($targetDate) {
                $query->where('date', $targetDate);
            })
            ->with([
                'client.mealTypes',
                'client.ingredientExclusions',
                'client.dishExclusions',
                'replacements.replacementProduct',
                'replacements.replacementDish',
            ])
            ->get();

        $stickers = [];

        foreach ($orders as $order) {
            $calc = $this->calculateOrderPlan($order, $menu);

            if (empty($calc['items'])) {
                continue;
            }

            $clientComment = $order->client->production_comment ?? null;
            $orderComment  = $order->comment ?? null;
            $globalNote    = trim(($clientComment ? "Клієнт: $clientComment. " : "") . ($orderComment ? "Зам: $orderComment" : ""));

            foreach ($calc['items'] as $it) {
                $dishId     = $it['dish_id'] ?? null;
                $mealTypeId = $it['meal_type_id'] ?? null;
                if (!$dishId) continue;

                $menuItem = $menu->menuItems->first(function ($mi) use ($dishId, $mealTypeId) {
                    return (int)$mi->dish_id === (int)$dishId && (int)$mi->meal_type_id === (int)$mealTypeId;
                });
                if (!$menuItem || !$menuItem->dish) continue;

                $dish = $menuItem->dish;

                $changes = [];
                if (!empty($globalNote)) $changes[] = "⚠️ " . $globalNote;

                $dishRep = $order->replacements->where('dish_id', $dish->id)->whereNull('original_product_id')->first();
                if ($dishRep && $dishRep->replacementDish) {
                    $changes[] = "🔄 ЗАМІНА СТРАВИ: " . $dishRep->replacementDish->name;
                } elseif ($order->client->dishExclusions->contains('id', $dish->id)) {
                    $changes[] = "⛔ КЛІЄНТ НЕ ЇСТЬ ЦЮ СТРАВУ!";
                } else {
                    $ingredientChanges = $this->findIngredientChanges($dish, $order, $dish->id);
                    $changes = array_merge($changes, $ingredientChanges);
                }

                if (!empty($changes)) {
                    $stickers[] = [
                        'client'    => $order->client?->name ?? 'Без імені',
                        'client_id' => $order->client?->id ?? '---',
                        'meal'      => $menuItem->mealType?->name ?? 'Прийом',
                        'dish'      => $dish->name,
                        'weight'    => (int) $it['weight'],
                        'time'      => $menuItem->mealType?->sort_order ?? 99,
                        'calories'  => (int) $order->calories,
                        'project'   => $order->project,
                        'changes'   => $changes,
                        'date'      => $targetDate,
                    ];
                }
            }
        }

        usort($stickers, fn($a, $b) => strcmp($a['client'], $b['client']) ?: $a['time'] <=> $b['time']);

        $date = $inputDate;
        return view('print.stickers', compact('stickers', 'date'));
    }

    /**
     * ✅ Фасувальний лист: ингредиенты масштабируются от РЕАЛЬНОГО веса блюда.
     * ✅ Исправлено: в колонках суммируем scale (sum_scale), а не храним один scale.
     */
    public function packagingList(Request $request)
    {
        $inputDate  = $request->input('date', now()->format('Y-m-d'));
        $targetDate = Carbon::parse($inputDate)->addDay()->format('Y-m-d');

        [$menu, $globalDay] = $this->getMenuForTargetDate($targetDate);

        if (!$menu) {
            return "Меню не знайдено на завтра ({$targetDate})";
        }

        $orders = Order::whereIn('status', ['new', 'active'])
            ->whereHas('orderDays', function ($query) use ($targetDate) {
                $query->where('date', $targetDate);
            })
            ->with([
                'client.mealTypes',
                'client.ingredientExclusions',
                'replacements.replacementProduct',
                'replacements.originalProduct',
            ])
            ->get();

        $report = [];

        $sortedMenuItems = $menu->menuItems->sortBy(fn($item) => $item->mealType?->sort_order ?? 99);

        foreach ($sortedMenuItems as $mItem) {
            $dish = $mItem->dish;
            if (!$dish) continue;

            $tableData = [
                'meal' => $mItem->mealType?->name ?? '-',
                'dish_name' => $dish->name,
                // columns: [kcal => ['count'=>int, 'sum_scale'=>float]]
                'columns' => [],
                'rows' => [],
                'individual_notes' => [],
            ];

            foreach ($orders as $order) {
                $calc = $this->calculateOrderPlan($order, $menu);

                $plannedDish = collect($calc['items'])->first(function ($it) use ($dish, $mItem) {
                    return (int)$it['dish_id'] === (int)$dish->id && (int)$it['meal_type_id'] === (int)$mItem->meal_type_id;
                });

                if (!$plannedDish) continue;

                $baseW = (float)($dish->base_weight_g ?? 0);
                $realW = (float)($plannedDish['weight'] ?? 0);
                $dishScale = ($baseW > 0) ? ($realW / $baseW) : 0.0;

                // заметки (оставляем твою логику)
                $replacements = $order->replacements
                    ->where('dish_id', $dish->id)
                    ->whereNotNull('original_product_id');

                $conflicts = [];
                if ($order->client->ingredientExclusions->isNotEmpty()) {
                    $conflicts = $this->getConflictingIngredients($dish, $order->client->ingredientExclusions);
                }

                $noteParts = [];
                foreach ($replacements as $r) {
                    $noteParts[] = "🔄 " . ($r->originalProduct->name ?? '?') . " ➡ " . ($r->replacementProduct->name ?? '?');
                }
                foreach ($conflicts as $conflictName) {
                    $noteParts[] = "⛔ Без: {$conflictName}";
                }

                if (!empty($noteParts)) {
                    $tableData['individual_notes'][] = "• (#{$order->client->id}) {$order->client->name}: " . implode(', ', $noteParts);
                }

                $colKey = (string) (int) ($order->calories ?? 0);

                if (!isset($tableData['columns'][$colKey])) {
                    $tableData['columns'][$colKey] = [
                        'count' => 0,
                        'sum_scale' => 0.0,
                    ];
                }

                $tableData['columns'][$colKey]['count']++;
                $tableData['columns'][$colKey]['sum_scale'] += $dishScale;
            }

            if (empty($tableData['columns'])) continue;

            ksort($tableData['columns']);

            foreach ($dish->dishIngredients as $di) {
                $originalName = $di->ingredient
                    ? $di->ingredient->name
                    : ($di->childDish ? "📦 " . $di->childDish->name : '???');

                $cells = [];
                foreach ($tableData['columns'] as $key => $col) {
                    $sumScale = (float)($col['sum_scale'] ?? 0.0);
                    $cells[$key] = [
                        'val' => round(((float)($di->net_weight_g ?? 0)) * $sumScale),
                    ];
                }

                $tableData['rows'][] = [
                    'original_name' => $originalName,
                    'cells' => $cells,
                ];
            }

            $report[] = $tableData;
        }

        $date = $inputDate;
        return view('print.packaging-list', compact('report', 'date'));
    }

    // =========================
    // ✅ СЕРДЦЕ РЕШЕНИЯ
    // =========================

    private function calculateOrderPlan(Order $order, DailyMenu $menu): array
    {
        $targetKcal = (float) ($order->calories ?? 0);
        if ($targetKcal <= 0) {
            return ['items' => [], 'totals' => ['kcal' => 0, 'prot' => 0, 'fat' => 0, 'carb' => 0]];
        }

        $clientMealTypeIds = $order->client?->mealTypes?->pluck('id')->toArray() ?? [];

        $availableItems = $menu->menuItems
            ->filter(fn($item) => $item->dish && in_array($item->meal_type_id, $clientMealTypeIds, true))
            ->sortBy(fn($item) => $item->mealType?->sort_order ?? 99)
            ->values();

        if ($availableItems->isEmpty()) {
            return ['items' => [], 'totals' => ['kcal' => 0, 'prot' => 0, 'fat' => 0, 'carb' => 0]];
        }

        $expectedDishes = $this->expectedDishCount((int)$targetKcal);

        $selectedItems = $availableItems->take($expectedDishes);

        if ($selectedItems->isEmpty()) {
            return ['items' => [], 'totals' => ['kcal' => 0, 'prot' => 0, 'fat' => 0, 'carb' => 0]];
        }

        $byMeal = $selectedItems->groupBy('meal_type_id');

        $percentSum = 0.0;
        foreach ($byMeal as $mealTypeId => $items) {
            $percentSum += (float) ($items->first()->mealType?->energy_percent ?? 0);
        }
        if ($percentSum <= 0) {
            $percentSum = 100.0;
        }

        $totals = ['kcal' => 0.0, 'prot' => 0.0, 'fat' => 0.0, 'carb' => 0.0];
        $resultItems = [];

        foreach ($byMeal as $mealTypeId => $items) {
            $mealType = $items->first()->mealType;
            $p = (float) ($mealType?->energy_percent ?? 0);

            $mealKcal = ($p > 0)
                ? $targetKcal * ($p / $percentSum)
                : $targetKcal * (1.0 / max(1, $byMeal->count()));

            $countInMeal = max(1, $items->count());
            $kcalPerDish = $mealKcal / $countInMeal;

            foreach ($items as $item) {
                $dish = $item->dish;
                if (!$dish) continue;

                $kcalPer100 = $this->dishKcalPer100g($dish);
                $weight = ($kcalPer100 > 0)
                    ? (int) round(($kcalPerDish / $kcalPer100) * 100.0)
                    : 0;

                $baseW = (float) ($dish->base_weight_g ?? 0);
                $protPerG = ($baseW > 0) ? ((float)($dish->total_prot ?? 0) / $baseW) : 0.0;
                $fatPerG  = ($baseW > 0) ? ((float)($dish->total_fat  ?? 0) / $baseW) : 0.0;
                $carbPerG = ($baseW > 0) ? ((float)($dish->total_carb ?? 0) / $baseW) : 0.0;

                $totals['kcal'] += ($weight * $kcalPer100 / 100.0);
                $totals['prot'] += ($weight * $protPerG);
                $totals['fat']  += ($weight * $fatPerG);
                $totals['carb'] += ($weight * $carbPerG);

                $resultItems[] = [
                    'meal' => $mealType?->name ?? '-',
                    'dish' => $dish->name,
                    'weight' => $weight,
                    'dish_id' => (int) $dish->id,
                    'meal_type_id' => (int) $mealTypeId,
                ];
            }
        }

        usort($resultItems, function ($a, $b) use ($menu) {
            $aSort = $menu->menuItems->firstWhere('meal_type_id', $a['meal_type_id'])?->mealType?->sort_order ?? 99;
            $bSort = $menu->menuItems->firstWhere('meal_type_id', $b['meal_type_id'])?->mealType?->sort_order ?? 99;
            return $aSort <=> $bSort;
        });

        return [
            'items' => $resultItems,
            'totals' => $totals,
        ];
    }

    private function expectedDishCount(int $kcal): int
    {
        if ($kcal < 1200) return 3;
        if ($kcal < 1500) return 4;
        return 5;
    }

    private function dishKcalPer100g($dish): float
    {
        $baseW = (float) ($dish->base_weight_g ?? 0);
        $totalKcal = (float) ($dish->total_kcal ?? 0);

        if ($baseW <= 0 || $totalKcal <= 0) {
            return 0.0;
        }

        return ($totalKcal / $baseW) * 100.0;
    }

    private function getMenuForTargetDate(string $targetDate): array
    {
        $cycleDays    = (int) Setting::where('key', 'menu_cycle_days')->value('value') ?: 24;
        $startDateStr = Setting::where('key', 'menu_cycle_start_date')->value('value') ?: '2025-01-01';
        $anchorDate   = Carbon::parse($startDateStr);

        $globalDay = (abs(Carbon::parse($targetDate)->diffInDays($anchorDate)) % $cycleDays) + 1;

        $menu = DailyMenu::where('day_number', $globalDay)
            ->with([
                'menuItems.dish.dishIngredients.ingredient',
                'menuItems.dish.dishIngredients.childDish.dishIngredients.ingredient',
                'menuItems.mealType',
            ])
            ->first();

        return [$menu, $globalDay];
    }

    private function findIngredientChanges($dishOrChildDish, $order, $rootDishId)
    {
        $changes = [];

        if (!$dishOrChildDish || !$dishOrChildDish->dishIngredients) {
            return $changes;
        }

        foreach ($dishOrChildDish->dishIngredients as $di) {
            if ($di->ingredient) {
                if ($order->client->ingredientExclusions->contains('id', $di->ingredient->id)) {
                    $ingRep = $order->replacements
                        ->where('dish_id', $rootDishId)
                        ->where('original_product_id', $di->ingredient->id)
                        ->first();

                    if ($ingRep) {
                        $changes[] = "🔄 " . $di->ingredient->name . " ➡ " . ($ingRep->replacementProduct->name ?? '?');
                    } else {
                        $changes[] = "❌ БЕЗ: " . $di->ingredient->name;
                    }
                }
            }

            if ($di->childDish) {
                $subChanges = $this->findIngredientChanges($di->childDish, $order, $rootDishId);
                $changes = array_merge($changes, $subChanges);
            }
        }

        return $changes;
    }

    private function getConflictingIngredients($dish, $exclusions, $prefix = ''): array
    {
        $found = [];

        if (!$dish || !$dish->dishIngredients) return [];

        foreach ($dish->dishIngredients as $di) {
            if ($di->ingredient_id && $exclusions->contains('id', $di->ingredient_id)) {
                $found[] = $di->ingredient->name . ($prefix ? " (у {$prefix})" : "");
            }
            if ($di->child_dish_id && $di->childDish) {
                $found = array_merge($found, $this->getConflictingIngredients($di->childDish, $exclusions, $di->childDish->name));
            }
        }

        return $found;
    }
}