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

    /**
     * 3. ФАСУВАЛЬНИЙ ЛИСТ (Packaging List) - Новий метод
     */
    public function packagingList(Request $request)
    {
        $date = $request->input('date', now()->format('Y-m-d'));
        
        // --- ТУТ ЛОГІКА РОЗРАХУНКУ МАТРИЦІ ---
        // (Скопійована і адаптована з Livewire компонента PackagingList)
        
        $cycleDays = (int) Setting::where('key', 'menu_cycle_days')->value('value') ?: 24;
        $anchorDate = Carbon::parse('2025-01-01'); 
        $carbonDate = Carbon::parse($date);
        $globalDay = (abs($carbonDate->diffInDays($anchorDate)) % $cycleDays) + 1;

        $menu = DailyMenu::where('day_number', $globalDay)
            ->with(['menuItems.dish.dishIngredients.ingredient', 'menuItems.mealType'])
            ->first();
        
        if (!$menu) {
             // Можна повернути просту сторінку помилки або пустий звіт
             return view('print.packaging-list', ['report' => [], 'date' => $date]);
        }

        $orders = Order::where('start_date', '<=', $date)
                       ->where('end_date', '>=', $date)
                       ->whereIn('status', ['new', 'active', 'completed'])
                       ->with(['client.mealTypes', 'client.ingredientExclusions', 'client.dishExclusions', 'replacements'])
                       ->get();

        $report = [];
        // Групуємо унікальні програми (калорійність)
        $caloriesList = $orders->pluck('calories')->unique()->sort()->values();

        foreach ($menu->menuItems as $menuItem) {
            $dish = $menuItem->dish;
            if (!$dish) continue;

            $columns = [];
            $totalCount = 0;
            $individualNotes = [];

            // Розрахунок по кожній групі калорій
            foreach ($caloriesList as $cal) {
                // Фільтруємо замовлення з цією калорійністю, які їдять цей прийом їжі
                $groupOrders = $orders->filter(function($o) use ($cal, $menuItem) {
                    return $o->calories == $cal && 
                           $o->client->mealTypes->contains('id', $menuItem->meal_type_id);
                });

                $count = $groupOrders->count();
                if ($count > 0) {
                    $totalCount += $count;
                    
                    // Збираємо імена клієнтів (для підказки)
                    $clientNames = $groupOrders->map(fn($o) => "ID:{$o->client_id} {$o->client->name} ({$o->calories})")->implode(', ');
                    
                    $columns[$cal] = [
                        'count' => $count,
                        'clients_hint' => $clientNames
                    ];
                }
            }

            if ($totalCount === 0) continue;

            // Збір інгредієнтів
            $rows = [];
            foreach ($dish->dishIngredients as $ingItem) {
                if (!$ingItem->ingredient) continue;
                
                $rowCells = [];
                foreach ($columns as $cal => $colInfo) {
                    // Знаходимо масштаб для цієї калорійності
                    // (Тут спрощено беремо масштаб першого замовлення з цієї групи, бо вони однакові)
                    $sampleOrder = $orders->where('calories', $cal)->first();
                    $scale = (float)($sampleOrder->scale_factor ?? 1.0);
                    
                    $val = round($ingItem->net_weight_g * $scale);
                    $rowCells[$cal] = ['val' => $val];
                }
                
                $rows[] = [
                    'original_name' => $ingItem->ingredient->name,
                    'cells' => $rowCells
                ];
            }

            // Перевірка індивідуальних замін для цього прийому
            foreach ($orders as $order) {
                if (!$order->client->mealTypes->contains('id', $menuItem->meal_type_id)) continue;

                // Заміни інгредієнтів
                foreach ($dish->dishIngredients as $di) {
                    if ($order->client->ingredientExclusions->contains('id', $di->ingredient_id)) {
                        $rep = $order->replacements
                            ->where('dish_id', $dish->id)
                            ->where('original_product_id', $di->ingredient_id)
                            ->first();

                        $note = "• (#{$order->client->id}) Клієнт {$order->client->id}: ";
                        if ($rep && $rep->replacementProduct) {
                            $note .= "{$di->ingredient->name} ➡️ {$rep->replacementProduct->name}";
                        } else {
                            $note .= "❌ Без: {$di->ingredient->name}";
                        }
                        $individualNotes[] = $note;
                    }
                }
            }

            $report[] = [
                'meal' => $menuItem->mealType->name,
                'dish_name' => $dish->name,
                'columns' => $columns,
                'rows' => $rows,
                'individual_notes' => $individualNotes
            ];
        }

        return view('print.packaging-list', compact('report', 'date'));
    }
}