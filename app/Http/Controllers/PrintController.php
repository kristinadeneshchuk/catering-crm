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
     * 1. ДРУК СТІКЕРІВ (Тільки для замовлень із замінами/коментарями)
     */
    public function stickers(Request $request)
    {
        $date = $request->input('date', now()->format('Y-m-d'));

        // 1. Розрахунок циклу
        $cycleDays = (int) Setting::where('key', 'menu_cycle_days')->value('value') ?: 24;
        $anchorDate = Carbon::parse('2025-01-01');
        $carbonDate = Carbon::parse($date);
        $diffInDays = abs($carbonDate->diffInDays($anchorDate));
        $globalDay = ($diffInDays % $cycleDays) + 1;

        // 2. Завантажуємо меню
        $menu = DailyMenu::where('day_number', $globalDay)
            ->with(['menuItems.dish.dishIngredients.ingredient', 'menuItems.mealType'])
            ->first();

        if (!$menu) return "❌ Меню ще не створено.";

        // 3. Завантажуємо замовлення
        $orders = Order::where('start_date', '<=', $date)
                       ->where('end_date', '>=', $date)
                       ->whereIn('status', ['new', 'active', 'completed'])
                       // ВАЖЛИВО: підвантажуємо заміни продуктів (replacementProduct)
                       ->with(['client.ingredientExclusions', 'client.dishExclusions', 'replacements.replacementProduct', 'replacements.replacementDish'])
                       ->get();

        $stickers = [];

        foreach ($orders as $order) {
            $scale = (float)($order->scale_factor ?: 1.0);
            
            // Збираємо коментар (глобальний)
            $clientComment = $order->client->production_comment ?? $order->client->comment ?? null;
            $globalNote = trim(($clientComment ?? '') . ' ' . ($order->comment ?? ''));

            foreach ($menu->menuItems as $item) {
                if (!$item->dish) continue;
                $dish = $item->dish;

                // === ЗБИРАЄМО СПИСОК ЗМІН ===
                $changes = [];

                // 1. Додаємо глобальний коментар
                if (!empty($globalNote)) {
                    $changes[] = "⚠️ " . $globalNote;
                }

                // 2. Перевірка заміни/виключення СТРАВИ
                $dishRep = $order->replacements->where('dish_id', $dish->id)->whereNull('original_product_id')->first();
                $isDishExcluded = $order->client->dishExclusions->contains('id', $dish->id);

                if ($dishRep && $dishRep->replacementDish) {
                    $changes[] = "🔄 ЗАМІНА СТРАВИ НА: " . $dishRep->replacementDish->name;
                } elseif ($isDishExcluded) {
                    $changes[] = "⛔ КЛІЄНТ НЕ ЇСТЬ ЦЮ СТРАВУ!";
                }

                // 3. Перевірка ІНГРЕДІЄНТІВ
                foreach ($dish->dishIngredients as $di) {
                    if (!$di->ingredient) continue;
                    
                    if ($order->client->ingredientExclusions->contains('id', $di->ingredient->id)) {
                        $ingRep = $order->replacements
                            ->where('dish_id', $dish->id)
                            ->where('original_product_id', $di->ingredient->id)
                            ->first();

                        if ($ingRep && $ingRep->replacementProduct) {
                            $changes[] = "🔄 " . $di->ingredient->name . " -> " . $ingRep->replacementProduct->name;
                        } else {
                            $changes[] = "❌ БЕЗ: " . $di->ingredient->name;
                        }
                    }
                }

                // === ЯКЩО Є ЗМІНИ — ДОДАЄМО В ДРУК ===
                if (!empty($changes)) {
                    $weight = round(($dish->base_weight_g ?? 0) * $scale);
                    
                    $stickers[] = [
                        'client'   => $order->client?->name ?? 'Без імені',
                        'meal'     => $item->mealType?->name ?? 'Прийом',
                        'dish'     => $dish->name,
                        'weight'   => $weight,
                        'time'     => $item->mealType?->sort_order ?? 99,
                        'calories' => $order->calories,
                        'project'  => $order->project,
                        'changes'  => $changes, 
                        'date'     => $date,
                    ];
                }
            }
        }

        // Сортування: за клієнтом, потім за часом прийому
        usort($stickers, fn($a, $b) => strcmp($a['client'], $b['client']) ?: $a['time'] <=> $b['time']);

        return view('print.stickers', compact('stickers', 'date'));
    }

    /**
     * 2. ДРУК НАКЛАДНОЇ (Маніфест)
     */
    public function manifest(Request $request)
    {
        $date = $request->input('date', now()->format('Y-m-d'));

        $cycleDays = (int) Setting::where('key', 'menu_cycle_days')->value('value') ?: 24;
        $anchorDate = Carbon::parse('2025-01-01');
        $carbonDate = Carbon::parse($date);
        $globalDay = (abs($carbonDate->diffInDays($anchorDate)) % $cycleDays) + 1;

        $menu = DailyMenu::where('day_number', $globalDay)
            ->with(['menuItems.dish', 'menuItems.mealType'])
            ->first();

        if (!$menu) {
            return "❌ На День циклу №{$globalDay} меню ще не створено.";
        }

        $orders = Order::where('start_date', '<=', $date)
                       ->where('end_date', '>=', $date)
                       ->whereIn('status', ['new', 'active', 'completed'])
                       ->with('client')
                       ->get();

        $manifests = [];

        foreach ($orders as $order) {
            $scale = (float)($order->scale_factor ?: 1.0);
            $items = [];
            
            $sortedMenuItems = $menu->menuItems->sortBy(fn($item) => $item->mealType?->sort_order ?? 99);

            foreach ($sortedMenuItems as $item) {
                $items[] = [
                    'meal' => $item->mealType?->name ?? '-',
                    'dish' => $item->dish->name,
                    'weight' => round(($item->dish->base_weight_g ?? 0) * $scale),
                ];
            }

            $manifests[] = [
                'order_id' => $order->id,
                'project'  => $order->project,
                'client'   => $order->client?->name ?? 'Без імені',
                'phone'    => $order->client?->phone ?? '---',
                'address'  => $order->client?->address ?? 'Адреса не вказана',
                'calories' => $order->calories,
                'comment'  => $order->comment ?? $order->client?->production_comment,
                'items'    => $items,
                'date'     => $date,
            ];
        }

        return view('print.manifest', compact('manifests', 'date'));
    }

 public function packagingList(Request $request)
    {
        $date = $request->input('date', now()->format('Y-m-d'));
        
        $cycleDays = (int) Setting::where('key', 'menu_cycle_days')->value('value') ?: 24;
        $anchorDate = Carbon::parse('2025-01-01');
        $carbonDate = Carbon::parse($date);
        $globalDay = (abs($carbonDate->diffInDays($anchorDate)) % $cycleDays) + 1;

        $menu = DailyMenu::where('day_number', $globalDay)
            ->with(['menuItems.dish.dishIngredients.ingredient', 'menuItems.dish.dishIngredients.childDish', 'menuItems.mealType'])
            ->first();
        
        if (!$menu) {
             return "Меню не знайдено на {$date}";
        }

        $orders = Order::where('start_date', '<=', $date)
                       ->where('end_date', '>=', $date)
                       ->whereIn('status', ['new', 'active', 'completed'])
                       ->with(['client.mealTypes', 'client.ingredientExclusions', 'replacements.replacementProduct', 'replacements.originalProduct'])
                       ->get();

        $report = [];

        $sortedMenuItems = $menu->menuItems->sortBy(fn($item) => $item->mealType->sort_order ?? 99);

        foreach ($sortedMenuItems as $mItem) {
            $dish = $mItem->dish;
            if (!$dish) continue;

            $tableData = [
                'meal' => $mItem->mealType->name ?? 'Інше',
                'dish_name' => $dish->name,
                'columns' => [], 
                'rows' => [],
                'individual_notes' => [] 
            ];

            foreach ($orders as $order) {
                $clientMealTypeIds = $order->client->mealTypes->pluck('id')->toArray();
                if (!in_array($mItem->meal_type_id, $clientMealTypeIds)) continue;

                $activePercentSum = $order->client->mealTypes->sum('energy_percent');
                $factor = ($activePercentSum > 0) ? (100 / $activePercentSum) : 1.0;
                $scale = (float)($order->scale_factor ?: 1.0) * $factor;

                // --- 1. ЗБИРАЄМО НОТАТКИ (ЗАМІНИ) ---
                $replacements = $order->replacements->where('dish_id', $dish->id)->whereNotNull('original_product_id');
                
                $conflicts = [];
                if ($order->client->ingredientExclusions->isNotEmpty()) {
                    $conflicts = $this->getConflictingIngredients($dish, $order->client->ingredientExclusions);
                }

                $noteParts = [];
                foreach($replacements as $r) {
                    $noteParts[] = "🔄 " . ($r->originalProduct->name ?? '?') . " ➡ " . ($r->replacementProduct->name ?? '?');
                }
                foreach($conflicts as $cName) {
                    $noteParts[] = "⛔ Без: {$cName}";
                }

                if (!empty($noteParts)) {
                     // Додаємо в список нотаток з ID клієнта
                     $tableData['individual_notes'][] = "• (#{$order->client->id}) {$order->client->name}: " . implode(', ', $noteParts);
                }

                // --- 2. ВИЗНАЧАЄМО КОЛОНКУ ---
                // Окрема колонка ТІЛЬКИ якщо змінено масштаб порції
                $isCustomColumn = ($factor > 1.01); 

                if ($isCustomColumn) {
                    $colKey = "ID:{$order->client->id} {$order->client->name} (" . (int)$order->calories . ")";
                } else {
                    $colKey = (int)$order->calories;
                }

                if (!isset($tableData['columns'][$colKey])) {
                    $tableData['columns'][$colKey] = ['count' => 0, 'scale' => $scale];
                }
                $tableData['columns'][$colKey]['count']++;
            }

            ksort($tableData['columns']);

            foreach ($dish->dishIngredients as $di) {
                if ($di->ingredient) {
                    $originalName = $di->ingredient->name;
                } elseif ($di->childDish) {
                    $originalName = "📦 " . $di->childDish->name;
                } else {
                    $originalName = '???';
                }

                $cells = [];
                foreach ($tableData['columns'] as $key => $col) {
                    $cells[$key] = ['val' => round($di->net_weight_g * $col['scale'])];
                }
                $tableData['rows'][] = ['original_name' => $originalName, 'cells' => $cells];
            }

            if (!empty($tableData['columns'])) $report[] = $tableData;
        }

        return view('print.packaging-list', compact('report', 'date'));
    }

    private function getConflictingIngredients($dish, $exclusions, $prefix = ''): array
    {
        $found = [];
        if (!$dish || !$dish->dishIngredients) return [];

        foreach ($dish->dishIngredients as $di) {
            if ($di->ingredient_id && $exclusions->contains('id', $di->ingredient_id)) {
                $name = $di->ingredient->name . ($prefix ? " (у {$prefix})" : "");
                $found[] = $name;
            }
            if ($di->child_dish_id && $di->childDish) {
                $subFound = $this->getConflictingIngredients($di->childDish, $exclusions, $di->childDish->name);
                $found = array_merge($found, $subFound);
            }
        }
        return $found;
    }
}