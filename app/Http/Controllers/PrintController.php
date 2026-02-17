<?php

namespace App\Http\Controllers;

use App\Models\DailyMenu;
use App\Models\Order;
use App\Models\Setting;
use Illuminate\Http\Request;
use Carbon\Carbon;

class PrintController extends Controller
{
    // ... (методи manifest та stickers можна залишити без змін, якщо вони працюють)
    // Я продублюю manifest та stickers, щоб був повний файл, 
    // але основні зміни у методі packagingList внизу.

    public function manifest(Request $request)
    {
        $inputDate = $request->input('date', now()->format('Y-m-d'));
        $targetDate = Carbon::parse($inputDate)->addDay()->format('Y-m-d');

        $cycleDays = (int) Setting::where('key', 'menu_cycle_days')->value('value') ?: 24;
        $startDateStr = Setting::where('key', 'menu_cycle_start_date')->value('value') ?: '2025-01-01';
        $anchorDate = Carbon::parse($startDateStr);
        $globalDay = (abs(Carbon::parse($targetDate)->diffInDays($anchorDate)) % $cycleDays) + 1;

        $menu = DailyMenu::where('day_number', $globalDay)
            ->with(['menuItems.dish', 'menuItems.mealType'])
            ->first();

        if (!$menu) return "❌ На День циклу №{$globalDay} меню ще не створено.";

        $orders = Order::whereIn('status', ['new', 'active'])
                       ->whereHas('orderDays', function ($query) use ($targetDate) {
                           $query->where('date', $targetDate);
                       })
                       ->with('client')
                       ->get();

        $manifests = [];
        foreach ($orders as $order) {
            $scale = (float)($order->scale_factor ?: 1.0);
            $items = [];
            $sortedMenuItems = $menu->menuItems->sortBy(fn($item) => $item->mealType?->sort_order ?? 99);

            foreach ($sortedMenuItems as $item) {
                $clientMealTypes = $order->client->mealTypes->pluck('id')->toArray();
                if (!in_array($item->meal_type_id, $clientMealTypes)) continue;

                $items[] = [
                    'meal' => $item->mealType?->name ?? '-',
                    'dish' => $item->dish->name,
                    'weight' => round(($item->dish->base_weight_g ?? 0) * $scale),
                ];
            }

            if (empty($items)) continue;

            $manifests[] = [
                'client_id' => $order->client?->id ?? '---',
                'has_cutlery' => (bool) ($order->client?->has_cutlery ?? true), 
                'project'   => $order->project,
                'client'    => $order->client?->name ?? 'Без імені',
                'address'   => $order->client?->address ?? 'Самовивіз',
                'calories'  => (int)$order->calories,
                'comment'   => $order->comment ?? $order->client?->production_comment,
                'items'     => $items,
                'date'      => $targetDate,
            ];
        }

        usort($manifests, function($a, $b) {
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
        $inputDate = $request->input('date', now()->format('Y-m-d'));
        $targetDate = Carbon::parse($inputDate)->addDay()->format('Y-m-d');

        $cycleDays = (int) Setting::where('key', 'menu_cycle_days')->value('value') ?: 24;
        $startDateStr = Setting::where('key', 'menu_cycle_start_date')->value('value') ?: '2025-01-01';
        $anchorDate = Carbon::parse($startDateStr);
        $globalDay = (abs(Carbon::parse($targetDate)->diffInDays($anchorDate)) % $cycleDays) + 1;

        $menu = DailyMenu::where('day_number', $globalDay)
            ->with(['menuItems.dish.dishIngredients.ingredient', 'menuItems.mealType'])
            ->first();

        if (!$menu) return "❌ Меню не створено на завтра ({$targetDate}).";

        $orders = Order::whereIn('status', ['new', 'active'])
                       ->whereHas('orderDays', function ($query) use ($targetDate) {
                           $query->where('date', $targetDate);
                       })
                       ->with(['client.ingredientExclusions', 'client.dishExclusions', 'replacements.replacementProduct', 'replacements.replacementDish'])
                       ->get();

        $stickers = [];
        foreach ($orders as $order) {
            $scale = (float)($order->scale_factor ?: 1.0);
            $clientComment = $order->client->production_comment ?? $order->client->comment ?? null;
            $globalNote = trim(($clientComment ?? '') . ' ' . ($order->comment ?? ''));
            $clientMealTypeIds = $order->client->mealTypes->pluck('id')->toArray();

            foreach ($menu->menuItems as $item) {
                if (!$item->dish) continue;
                if (!in_array($item->meal_type_id, $clientMealTypeIds)) continue;

                $dish = $item->dish;
                $changes = [];

                if (!empty($globalNote)) $changes[] = "⚠️ " . $globalNote;

                $dishRep = $order->replacements->where('dish_id', $dish->id)->whereNull('original_product_id')->first();
                if ($dishRep && $dishRep->replacementDish) {
                    $changes[] = "🔄 ЗАМІНА СТРАВИ: " . $dishRep->replacementDish->name;
                } elseif ($order->client->dishExclusions->contains('id', $dish->id)) {
                    $changes[] = "⛔ КЛІЄНТ НЕ ЇСТЬ ЦЮ СТРАВУ!";
                }

                foreach ($dish->dishIngredients as $di) {
                    if (!$di->ingredient) continue;
                    if ($order->client->ingredientExclusions->contains('id', $di->ingredient->id)) {
                        $ingRep = $order->replacements->where('dish_id', $dish->id)->where('original_product_id', $di->ingredient->id)->first();
                        $changes[] = $ingRep ? "🔄 " . $di->ingredient->name . " -> " . $ingRep->replacementProduct->name : "❌ БЕЗ: " . $di->ingredient->name;
                    }
                }

                $stickers[] = [
                    'client'    => $order->client?->name ?? 'Без імені',
                    'client_id' => $order->client?->id ?? '---',
                    'meal'      => $item->mealType?->name ?? 'Прийом',
                    'dish'      => $dish->name,
                    'weight'    => round(($dish->base_weight_g ?? 0) * $scale),
                    'time'      => $item->mealType?->sort_order ?? 99,
                    'calories'  => $order->calories,
                    'project'   => $order->project,
                    'changes'   => $changes, 
                    'date'      => $targetDate,
                ];
            }
        }
        usort($stickers, fn($a, $b) => strcmp($a['client'], $b['client']) ?: $a['time'] <=> $b['time']);
        $date = $inputDate;
        return view('print.stickers', compact('stickers', 'date'));
    }

    /**
     * 🔥 ВИПРАВЛЕНИЙ МЕТОД ФАСУВАЛЬНОГО ЛИСТА
     */
    public function packagingList(Request $request)
    {
        $inputDate = $request->input('date', now()->format('Y-m-d'));
        // Ми залишаємо addDay(), тому з адмінки треба передавати 17.02
        $targetDate = Carbon::parse($inputDate)->addDay()->format('Y-m-d');
        
        $cycleDays = (int) Setting::where('key', 'menu_cycle_days')->value('value') ?: 24;
        $startDateStr = Setting::where('key', 'menu_cycle_start_date')->value('value') ?: '2025-01-01';
        $anchorDate = Carbon::parse($startDateStr);
        $globalDay = (abs(Carbon::parse($targetDate)->diffInDays($anchorDate)) % $cycleDays) + 1;

        $menu = DailyMenu::where('day_number', $globalDay)
            ->with(['menuItems.dish.dishIngredients.ingredient', 'menuItems.dish.dishIngredients.childDish.dishIngredients.ingredient', 'menuItems.mealType'])
            ->first();
        
        if (!$menu) return "Меню не знайдено на завтра ({$targetDate})";

        $orders = Order::whereIn('status', ['new', 'active'])
                       ->whereHas('orderDays', function ($query) use ($targetDate) {
                           $query->where('date', $targetDate);
                       })
                       ->with(['client.mealTypes', 'client.ingredientExclusions', 'replacements.replacementProduct', 'replacements.originalProduct'])
                       ->get();

        $report = [];
        $sortedMenuItems = $menu->menuItems->sortBy(fn($item) => $item->mealType->sort_order ?? 99);

        foreach ($sortedMenuItems as $mItem) {
            $dish = $mItem->dish;
            if (!$dish) continue;

            $tableData = [
                'meal' => $mItem->mealType->name, 
                'dish_name' => $dish->name, 
                'columns' => [], 
                'rows' => [], 
                'individual_notes' => []
            ];

            foreach ($orders as $order) {
                if (!in_array($mItem->meal_type_id, $order->client->mealTypes->pluck('id')->toArray())) continue;

                // === 🔥 НОВА ЛОГІКА СТАНДАРТІВ 🔥 ===
                $kcal = (int)$order->calories;
                $mealsCount = $order->client->mealTypes->count();
                
                // Визначаємо стандартну кількість страв для цих калорій
                $expectedMeals = 5; // За замовчуванням (1500+)
                if ($kcal < 1200) {
                    $expectedMeals = 3; // Для 950 ккал
                } elseif ($kcal < 1500) {
                    $expectedMeals = 4; // Для 1200-1499 ккал
                }

                // Якщо кількість страв відповідає стандарту -> фактор 1.0 (НЕ індивідуальний)
                if ($mealsCount === $expectedMeals) {
                    $factor = 1.0;
                } else {
                    $activePercentSum = $order->client->mealTypes->sum('energy_percent');
                    $factor = ($activePercentSum > 0) ? (100 / $activePercentSum) : 1.0;
                }
                
                $scale = (float)($order->scale_factor ?: 1.0) * $factor;
                // ===========================================

                // Примітки
                $replacements = $order->replacements->where('dish_id', $dish->id)->whereNotNull('original_product_id');
                $conflicts = [];
                if ($order->client->ingredientExclusions->isNotEmpty()) {
                    $conflicts = $this->getConflictingIngredients($dish, $order->client->ingredientExclusions);
                }

                $noteParts = [];
                foreach($replacements as $r) {
                    $noteParts[] = "🔄 " . ($r->originalProduct->name ?? '?') . " ➡ " . ($r->replacementProduct->name ?? '?');
                }
                foreach($conflicts as $conflictName) {
                    $noteParts[] = "⛔ Без: {$conflictName}";
                }

                if (!empty($noteParts)) {
                    $tableData['individual_notes'][] = "• (#{$order->client->id}) {$order->client->name}: " . implode(', ', $noteParts);
                }

                // Групування колонок
                if ($factor >= 0.99 && $factor <= 1.01) {
                    // Стандарт
                    $colKey = (string)(int)$order->calories;
                } else {
                    // Індивідуальне (групуємо однакових "нестандартних")
                    $colKey = (int)$order->calories . ' (Інд. x' . round($factor, 2) . ')';
                }

                if (!isset($tableData['columns'][$colKey])) {
                    $tableData['columns'][$colKey] = ['count' => 0, 'scale' => $scale];
                }
                $tableData['columns'][$colKey]['count']++;
            }

            ksort($tableData['columns']);

            foreach ($dish->dishIngredients as $di) {
                $originalName = $di->ingredient ? $di->ingredient->name : ($di->childDish ? "📦 " . $di->childDish->name : '???');
                $cells = [];
                foreach ($tableData['columns'] as $key => $col) {
                    $cells[$key] = ['val' => round($di->net_weight_g * $col['scale'])];
                }
                $tableData['rows'][] = ['original_name' => $originalName, 'cells' => $cells];
            }
            if (!empty($tableData['columns'])) $report[] = $tableData;
        }

        $date = $inputDate;
        return view('print.packaging-list', compact('report', 'date'));
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