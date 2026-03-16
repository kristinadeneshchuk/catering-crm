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
        $layout     = $request->input('layout', 'default');
        $targetDate = Carbon::parse($inputDate)->addDay()->format('Y-m-d');

        [$menu, $globalDay] = $this->getMenuForTargetDate($targetDate);

        if (!$menu) {
            return "❌ На День циклу №{$globalDay} меню ще не створено.";
        }

        $orders = Order::whereHas('orderDays', function ($query) use ($targetDate) {
                $query->where('date', $targetDate);
            })
            ->with(['client.mealTypes', 'projectData']) 
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
                'comment'     => $order->client?->production_comment,
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

        // 🔥 ВИПРАВЛЕННЯ: Передаємо базову дату, щоб уникнути "+2 дні" в шаблоні
        $date = $inputDate; 
        return view('print.manifest', compact('manifests', 'date', 'layout'));
    }

    public function miniManifest(Request $request)
    {
        $inputDate  = $request->input('date', now()->format('Y-m-d'));
        $targetDate = Carbon::parse($inputDate)->addDay()->format('Y-m-d');

        $orders = Order::whereHas('orderDays', function ($query) use ($targetDate) {
                $query->where('date', $targetDate);
            })
            ->with(['client', 'projectData']) 
            ->get();

        $manifests = [];

        foreach ($orders as $order) {
            $manifests[] = [
                'client_id'   => $order->client?->id ?? '---',
                'project'     => $order->project,
                'client'      => $order->client?->name ?? 'Без імені',
                'address'     => $order->client?->address ?? 'Самовивіз',
                'calories'    => (int) $order->calories,
            ];
        }

        usort($manifests, function ($a, $b) {
            return strcmp($a['client'], $b['client']);
        });

        // 🔥 ВИПРАВЛЕННЯ: Передаємо базову дату
        $date = $inputDate; 
        return view('print.mini-manifest', compact('manifests', 'date'));
    }

    public function stickers(Request $request)
    {
        $inputDate  = $request->input('date', now()->format('Y-m-d'));
        $targetDate = Carbon::parse($inputDate)->addDay()->format('Y-m-d');

        [$menu, $globalDay] = $this->getMenuForTargetDate($targetDate);

        if (!$menu) {
            return "❌ Меню не створено на завтра ({$targetDate}).";
        }

        $orders = Order::whereHas('orderDays', function ($query) use ($targetDate) {
                $query->where('date', $targetDate);
            })
            ->with([
                'client.mealTypes',
                'client.ingredientExclusions',
                'client.dishExclusions',
                'projectData', 
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

        // 🔥 ВИПРАВЛЕННЯ: Передаємо базову дату
        $date = $inputDate; 
        return view('print.stickers', compact('stickers', 'date'));
    }

    public function packagingList(Request $request)
    {
        $inputDate  = $request->input('date', now()->format('Y-m-d'));
        $targetDate = Carbon::parse($inputDate)->addDay()->format('Y-m-d');

        [$menu, $globalDay] = $this->getMenuForTargetDate($targetDate);

        if (!$menu) {
            return "Меню не знайдено на завтра ({$targetDate})";
        }

        $orders = Order::whereHas('orderDays', function ($query) use ($targetDate) {
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
                    $count = (int)($col['count'] ?? 1);
                    $sumScale = (float)($col['sum_scale'] ?? 0.0);
                    
                    $onePortionScale = $count > 0 ? ($sumScale / $count) : 0;

                    $cells[$key] = [
                        'val' => round(((float)($di->net_weight_g ?? 0)) * $onePortionScale),
                    ];
                }

                $tableData['rows'][] = [
                    'original_name' => $originalName,
                    'cells' => $cells,
                ];
            }

            $report[] = $tableData;
        }

        // 🔥 ВИПРАВЛЕННЯ: Передаємо базову дату
        $date = $inputDate; 
        return view('print.packaging-list', compact('report', 'date'));
    }

    public function productionReport(Request $request)
    {
        $inputDate = $request->input('date', now()->format('Y-m-d'));
        $targetDate = Carbon::parse($inputDate)->addDay()->format('Y-m-d');

        [$menu, $globalDay] = $this->getMenuForTargetDate($targetDate);

        if (!$menu) {
            return "❌ Меню не знайдено на завтра ({$targetDate})";
        }

        $orders = Order::whereHas('orderDays', function ($query) use ($targetDate) {
                $query->where('date', $targetDate);
            })
            ->with([
                'client.mealTypes',
                'client.ingredientExclusions',
                'client.dishExclusions',
                'replacements.replacementProduct',
                'replacements.replacementDish.dishIngredients.ingredient',
            ])
            ->get();

        if ($orders->isEmpty()) {
            return "Немає активних замовлень на {$targetDate}.";
        }

        $orderPlans = [];
        foreach ($orders as $order) {
            $orderPlans[$order->id] = $this->calculateOrderPlan($order, $menu);
        }

        $report = [];
        $sortedMenuItems = $menu->menuItems->sortBy(fn($item) => $item->mealType?->sort_order ?? 99);

        foreach ($sortedMenuItems as $item) {
            if (!$item->dish) continue;

            $mealName = $item->mealType->name ?? 'Інше';
            $dish = $item->dish;

            $standard = []; 
            $custom = [];   

            foreach ($orders as $order) {
                $plan = $orderPlans[$order->id] ?? null;
                if (!$plan) continue;

                $plannedWeight = collect($plan['items'])->first(function ($it) use ($dish, $item) {
                    return (int)$it['dish_id'] === (int)$dish->id && (int)$it['meal_type_id'] === (int)$item->meal_type_id;
                })['weight'] ?? null;

                if ($plannedWeight === null) continue;

                $baseW = (float)($dish->base_weight_g ?? 0);
                $dishScale = ($baseW > 0) ? ((float)$plannedWeight / $baseW) : 0.0;

                $isCustom =
                    (!empty($order->client->production_comment)) 
                    || $order->replacements->where('dish_id', $dish->id)->isNotEmpty()
                    || $order->client->dishExclusions->contains('id', $dish->id)
                    || !empty($this->getConflictingIngredients($dish, $order->client->ingredientExclusions));

                if ($isCustom) {
                    $custom[] = ['order' => $order, 'scale' => $dishScale];
                } else {
                    $standard[] = ['order' => $order, 'scale' => $dishScale];
                }
            }

            if (empty($standard) && empty($custom)) continue;

            $standardScales = array_map(fn($x) => (float)$x['scale'], $standard);
            $standardStructure = $this->calculateIngredientsStructureByScales($dish, $standardScales);
            $standardTotals = $this->calculateStructureTotals($standardStructure);

            $customCards = collect($custom)->map(function ($entry) use ($dish) {
                return $this->buildCustomCard($dish, $entry['order'], (float)$entry['scale']);
            })->toArray();

            $report[$mealName][] = [
                'meal_name' => $mealName,
                'dish_id' => $dish->id,
                'dish_name' => $dish->name,
                'standard_count' => count($standard),
                'standard_structure' => $standardStructure,
                'standard_total_netto' => $standardTotals['netto'],
                'standard_total_brutto' => $standardTotals['brutto'],
                'custom_cards' => $customCards,
            ];
        }

        return view('print.production-report', [
            'report' => $report,
            'date' => Carbon::parse($inputDate)->format('d.m.Y'),       
            'targetDateFormatted' => Carbon::parse($targetDate)->format('d.m.Y'),
            'targetDate' => $targetDate,
            'dayNumber' => $globalDay
        ]);
    }

    public function logistics(Request $request)
    {
        // Отримуємо дату (якщо немає - беремо сьогодні)
        $date = $request->input('date', now()->format('Y-m-d'));
        
        // Отримуємо зміну (morning або evening). По замовчуванню - ранок
        $shift = $request->input('shift', 'morning');

        // Формуємо красиву назву файлу (наприклад: logistics_morning_2026-03-16.xlsx)
        $fileName = "logistics_{$shift}_{$date}.xlsx";

        // Передаємо ДВА параметри в наш оновлений LogisticsExport
        return \Maatwebsite\Excel\Facades\Excel::download(new \App\Exports\LogisticsExport($date, $shift), $fileName);
    }

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

        // 🔥 ЗМІНЕНО: Рахуємо загальну суму відсотків з урахуванням кастомних
        $percentSum = 0.0;
        foreach ($byMeal as $mealTypeId => $items) {
            $firstItem = $items->first();
            $p = $firstItem->custom_energy_percent !== null 
                ? (float) $firstItem->custom_energy_percent 
                : (float) ($firstItem->mealType?->energy_percent ?? 0);
            
            $percentSum += $p;
        }
        
        if ($percentSum <= 0) {
            $percentSum = 100.0;
        }

        $totals = ['kcal' => 0.0, 'prot' => 0.0, 'fat' => 0.0, 'carb' => 0.0];
        $resultItems = [];

        foreach ($byMeal as $mealTypeId => $items) {
            $firstItem = $items->first();
            $mealType = $firstItem->mealType;
            
            // 🔥 ЗМІНЕНО: Беремо кастомний відсоток, якщо він є
            $p = $firstItem->custom_energy_percent !== null 
                ? (float) $firstItem->custom_energy_percent 
                : (float) ($mealType?->energy_percent ?? 0);

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
    
    private function calculateIngredientsStructureByScales($dish, array $scales): array
    {
        if (empty($scales)) return [];
        $totalScale = array_sum($scales);
        return $this->getHierarchicalIngredients($dish, $totalScale, 1.0, null, false, null);
    }

    private function calculateStructureTotals(array $components): array
    {
        $netto = 0.0; $brutto = 0.0;
        foreach ($components as $comp) {
            if (($comp['type'] ?? null) === 'pf') {
                $netto += (float)($comp['weight_output'] ?? 0);
                $brutto += (float)($comp['weight_brutto_sum'] ?? 0);
            } else {
                $netto += (float)($comp['weight_netto'] ?? 0);
                $brutto += (float)($comp['weight_brutto'] ?? 0);
            }
        }
        return ['netto' => round($netto), 'brutto' => round($brutto)];
    }

    private function buildCustomCard($dish, $order, float $scale): array
    {
        $dishExclusion = $order->client->dishExclusions->contains('id', $dish->id);
        $dishReplacement = $order->replacements->where('dish_id', $dish->id)->whereNull('original_product_id')->first();

        $replacementDishName = null;
        if ($dishReplacement && $dishReplacement->replacementDish) {
            $replacementDishName = $dishReplacement->replacementDish->name;
            $components = $this->getHierarchicalIngredients($dishReplacement->replacementDish, $scale, 1.0, $dishReplacement->replacementDish->id, true, $order);
        } else {
            $components = $this->getHierarchicalIngredients($dish, $scale, 1.0, $dish->id, true, $order);
        }

        $totals = $this->calculateStructureTotals($components);
        
        $finalComment = trim($order->client->production_comment ?? '');

        return [
            'client_name' => $order->client->name,
            'order_id' => $order->id,
            'comment' => $finalComment,
            'dish_excluded' => $dishExclusion,
            'dish_replacement' => $replacementDishName,
            'components' => $components,
            'total_netto' => $totals['netto'],
            'total_brutto' => $totals['brutto'],
        ];
    }

    private function getHierarchicalIngredients($dish, float $scale, float $subRatio = 1.0, $rootDishId = null, bool $checkConflicts = true, $specificOrder = null): array
    {
        $components = [];
        if (!$dish || !$dish->dishIngredients) return $components;
        if (!$rootDishId) $rootDishId = $dish->id;

        foreach ($dish->dishIngredients as $di) {
            $currentK = $scale * $subRatio;
            $type = mb_strtolower(trim((string)($di->type ?? '')));
            $nettoTotalRaw = (float)($di->net_weight_g ?? 0) * $currentK;

            $conflictData = null;
            $isProduct = in_array($type, ['product', 'продукт'], true);
            $isPf = in_array($type, ['pf', 'напівфабрикат', 'п/ф', 'н/ф'], true);

            if ($checkConflicts && $specificOrder && $isProduct && $di->ingredient) {
                $ingId = (int)$di->ingredient->id;
                if ($specificOrder->client->ingredientExclusions->contains('id', $ingId)) {
                    $rep = $specificOrder->replacements->where('dish_id', $rootDishId)->where('original_product_id', $ingId)->first();
                    $replacementInfo = null;
                    if ($rep && $rep->replacementProduct) {
                        $newYield = (float)($rep->replacementProduct->yield_percent ?: 100);
                        if ($newYield <= 0) $newYield = 100;
                        $replacementInfo = [
                            'name' => $rep->replacementProduct->name,
                            'netto' => round($nettoTotalRaw, 1),
                            'brutto' => round(($nettoTotalRaw * 100) / $newYield, 1),
                        ];
                    }
                    $conflictData = ['is_resolved' => (bool)$replacementInfo, 'replacement' => $replacementInfo];
                }
            }

            if ($isProduct && $di->ingredient) {
                $yield = (float)($di->ingredient->yield_percent ?: 100);
                if ($yield <= 0) $yield = 100;
                $components[] = [
                    'type' => 'product',
                    'name' => $di->ingredient->name,
                    'weight_netto' => round($nettoTotalRaw, 1),
                    'weight_brutto' => round(($nettoTotalRaw * 100) / $yield, 1),
                    'conflict' => $conflictData,
                ];
                continue;
            }

            if ($isPf && $di->childDish) {
                $pfTotals = $di->childDish->calculated_totals;
                $pfOutput = (float)($pfTotals['output_weight'] ?? 0);
                if ($pfOutput <= 0) continue;

                $pfRatio = ((float)($di->net_weight_g ?? 0)) / $pfOutput;
                $subIngredients = $this->getHierarchicalIngredients($di->childDish, $scale, ($pfRatio * $subRatio), $rootDishId, $checkConflicts, $specificOrder);
                
                $sumNetto = 0.0; $sumBrutto = 0.0;
                foreach ($subIngredients as $s) {
                    $sumNetto += (float)($s['weight_netto'] ?? ($s['weight_output'] ?? 0));
                    $sumBrutto += (float)($s['weight_brutto'] ?? ($s['weight_brutto_sum'] ?? 0));
                }

                $components[] = [
                    'type' => 'pf',
                    'name' => $di->childDish->name,
                    'weight_output' => round($nettoTotalRaw, 1),
                    'weight_netto_sum' => round($sumNetto, 1),
                    'weight_brutto_sum' => round($sumBrutto, 1),
                    'weight_netto' => round($sumNetto, 1),
                    'weight_brutto' => round($sumBrutto, 1),
                    'sub_ingredients' => $subIngredients
                ];
            }
        }
        return $components;
    }
}