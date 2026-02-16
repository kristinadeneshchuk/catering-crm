<?php

namespace App\Http\Controllers;

use App\Models\DailyMenu;
use App\Models\Order;
use App\Models\Setting;
use Illuminate\Http\Request;
use Carbon\Carbon;

class PrintController extends Controller
{
    /**
     * 1. ДРУК МАНІФЕСТІВ (На пакет)
     * Сортування: від менших ккал до більших
     * Дані: ID клієнта замість номера телефону
     */
    public function manifest(Request $request)
    {
        // 🔥 Отримуємо дату фасування (16.02) і перетворюємо на дату споживання (17.02)
        $inputDate = $request->input('date', now()->format('Y-m-d'));
        $targetDate = Carbon::parse($inputDate)->addDay()->format('Y-m-d');

        // Розрахунок дня циклу
        $cycleDays = (int) Setting::where('key', 'menu_cycle_days')->value('value') ?: 24;
        $startDateStr = Setting::where('key', 'menu_cycle_start_date')->value('value') ?: '2025-01-01';
        $anchorDate = Carbon::parse($startDateStr);
        $globalDay = (abs(Carbon::parse($targetDate)->diffInDays($anchorDate)) % $cycleDays) + 1;

        // Завантажуємо меню
        $menu = DailyMenu::where('day_number', $globalDay)
            ->with(['menuItems.dish', 'menuItems.mealType'])
            ->first();

        if (!$menu) return "❌ На День циклу №{$globalDay} меню ще не створено.";

        // Завантажуємо активні замовлення на завтра
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
            
            // Сортуємо страви за порядком прийомів їжі
            $sortedMenuItems = $menu->menuItems->sortBy(fn($item) => $item->mealType?->sort_order ?? 99);

            foreach ($sortedMenuItems as $item) {
                $items[] = [
                    'meal' => $item->mealType?->name ?? '-',
                    'dish' => $item->dish->name,
                    'weight' => round(($item->dish->base_weight_g ?? 0) * $scale),
                ];
            }

            $manifests[] = [
                'client_id' => $order->client?->id ?? '---', // Додаємо ID
                'project'   => $order->project,
                'client'    => $order->client?->name ?? 'Без імені',
                'address'   => $order->client?->address ?? 'Самовивіз',
                'calories'  => (int)$order->calories, // Число для сортування
                'comment'   => $order->comment ?? $order->client?->production_comment,
                'items'     => $items,
                'date'      => $targetDate,
            ];
        }

        // 🔥 СОРТУВАННЯ: спочатку за калоріями (ASC), потім за іменем
        usort($manifests, function($a, $b) {
            if ($a['calories'] === $b['calories']) {
                return strcmp($a['client'], $b['client']);
            }
            return $a['calories'] <=> $b['calories'];
        });

        $date = $inputDate; 
        return view('print.manifest', compact('manifests', 'date'));
    }

    /**
     * 2. ДРУК СТІКЕРІВ (На страви)
     */
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

            foreach ($menu->menuItems as $item) {
                if (!$item->dish) continue;
                $dish = $item->dish;
                $changes = [];

                if (!empty($globalNote)) $changes[] = "⚠️ " . $globalNote;

                // Перевірка замін
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

                if (!empty($changes)) {
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
        }

        // Сортуємо наклейки для зручності (за іменем та часом прийому)
        usort($stickers, fn($a, $b) => strcmp($a['client'], $b['client']) ?: $a['time'] <=> $b['time']);

        $date = $inputDate;
        return view('print.stickers', compact('stickers', 'date'));
    }

    /**
     * 3. ВЕРСІЯ ДЛЯ ДРУКУ ФАСУВАЛЬНОГО ЛИСТА (Матриця)
     */
    public function packagingList(Request $request)
    {
        $inputDate = $request->input('date', now()->format('Y-m-d'));
        $targetDate = Carbon::parse($inputDate)->addDay()->format('Y-m-d');
        
        $cycleDays = (int) Setting::where('key', 'menu_cycle_days')->value('value') ?: 24;
        $startDateStr = Setting::where('key', 'menu_cycle_start_date')->value('value') ?: '2025-01-01';
        $anchorDate = Carbon::parse($startDateStr);
        $globalDay = (abs(Carbon::parse($targetDate)->diffInDays($anchorDate)) % $cycleDays) + 1;

        $menu = DailyMenu::where('day_number', $globalDay)
            ->with(['menuItems.dish.dishIngredients.ingredient', 'menuItems.dish.dishIngredients.childDish', 'menuItems.mealType'])
            ->first();
        
        if (!$menu) return "Меню не знайдено на завтра ({$targetDate})";

        $orders = Order::whereIn('status', ['new', 'active'])
                       ->whereHas('orderDays', function ($query) use ($targetDate) {
                           $query->where('date', $targetDate);
                       })
                       ->with(['client.mealTypes', 'client.ingredientExclusions', 'replacements.replacementProduct', 'replacements.originalProduct'])
                       ->get();

        $report = [];
        foreach ($menu->menuItems->sortBy('mealType.sort_order') as $mItem) {
            $dish = $mItem->dish;
            if (!$dish) continue;

            $tableData = ['meal' => $mItem->mealType->name, 'dish_name' => $dish->name, 'columns' => [], 'rows' => [], 'individual_notes' => []];

            foreach ($orders as $order) {
                if (!in_array($mItem->meal_type_id, $order->client->mealTypes->pluck('id')->toArray())) continue;

                $factor = ($order->client->mealTypes->sum('energy_percent') > 0) ? (100 / $order->client->mealTypes->sum('energy_percent')) : 1.0;
                $scale = (float)($order->scale_factor ?: 1.0) * $factor;

                $colKey = ($factor > 1.01) ? "ID:{$order->client->id} {$order->client->name} (" . (int)$order->calories . ")" : (int)$order->calories;

                if (!isset($tableData['columns'][$colKey])) $tableData['columns'][$colKey] = ['count' => 0, 'scale' => $scale];
                $tableData['columns'][$colKey]['count']++;
            }

            ksort($tableData['columns']);

            foreach ($dish->dishIngredients as $di) {
                $originalName = $di->ingredient ? $di->ingredient->name : ($di->childDish ? "📦 " . $di->childDish->name : '???');
                $cells = [];
                foreach ($tableData['columns'] as $key => $col) $cells[$key] = ['val' => round($di->net_weight_g * $col['scale'])];
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
            if ($di->ingredient_id && $exclusions->contains('id', $di->ingredient_id)) $found[] = $di->ingredient->name . ($prefix ? " (у {$prefix})" : "");
            if ($di->child_dish_id && $di->childDish) $found = array_merge($found, $this->getConflictingIngredients($di->childDish, $exclusions, $di->childDish->name));
        }
        return $found;
    }
}