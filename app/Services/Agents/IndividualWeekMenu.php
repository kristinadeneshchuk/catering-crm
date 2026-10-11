<?php

namespace App\Services\Agents;

use App\Models\Dish;
use App\Models\DishIngredient;
use App\Models\Ingredient;
use App\Models\MealType;
use App\Models\Order;
use App\Models\OrderDayDish;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Пропозиція «індивідуальне меню»: нові страви з техкартами + страви по днях.
 * Після погодження CRM створює страви й персональне меню (order_day_dishes),
 * а звідти вже йдуть план виробництва, стікери, закупка.
 *
 * payload:
 *   order_id: int
 *   replace_days: bool (true — прибрати інше персональне меню цих дат)
 *   new_dishes: [{ref, name, group?, base_weight_g?, description?,
 *                 ingredients: [{ingredient_id|child_dish_id, net_weight_g}]}]
 *   days: [{date: Y-m-d, meals: [{meal_type_id, dish_id|dish_ref, weight_grams?, cooking_note?}]}]
 */
class IndividualWeekMenu
{
    /** @return string[] помилки — агент має їх виправити й подати знову */
    public function validate(array $p): array
    {
        $errors = [];
        $order = Order::find($p['order_id'] ?? null);
        if (!$order) $errors[] = 'order_id: замовлення не знайдено';

        $refs = [];
        foreach ((array) ($p['new_dishes'] ?? []) as $i => $d) {
            $ref = $d['ref'] ?? null;
            if (!$ref) $errors[] = "new_dishes[$i]: немає ref";
            if (blank($d['name'] ?? null)) $errors[] = "new_dishes[$i]: немає name";
            if (empty($d['ingredients'])) $errors[] = "new_dishes[$i] «{$ref}»: немає інгредієнтів";
            foreach ((array) ($d['ingredients'] ?? []) as $j => $ing) {
                $id = $ing['ingredient_id'] ?? null; $child = $ing['child_dish_id'] ?? null;
                if (!$id && !$child) $errors[] = "new_dishes[$i].ingredients[$j]: потрібен ingredient_id або child_dish_id";
                if ($id && !Ingredient::whereKey($id)->exists()) $errors[] = "new_dishes[$i].ingredients[$j]: продукту #{$id} немає";
                if ($child && !Dish::whereKey($child)->exists()) $errors[] = "new_dishes[$i].ingredients[$j]: напівфабрикату #{$child} немає";
                if ((float) ($ing['net_weight_g'] ?? 0) <= 0) $errors[] = "new_dishes[$i].ingredients[$j]: net_weight_g > 0";
            }
            if ($ref) $refs[$ref] = true;
        }

        $mealTypes = MealType::pluck('id')->all();
        if (empty($p['days'])) $errors[] = 'days: порожньо';
        foreach ((array) ($p['days'] ?? []) as $i => $day) {
            $date = $day['date'] ?? null;
            if (!$date || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) { $errors[] = "days[$i]: date у форматі Y-m-d"; continue; }
            foreach ((array) ($day['meals'] ?? []) as $j => $m) {
                if (!in_array((int) ($m['meal_type_id'] ?? 0), $mealTypes, true)) $errors[] = "{$date} meals[$j]: невідомий meal_type_id";
                $dishId = $m['dish_id'] ?? null; $ref = $m['dish_ref'] ?? null;
                if ($dishId && !Dish::whereKey($dishId)->exists()) $errors[] = "{$date} meals[$j]: страви #{$dishId} немає";
                if (!$dishId && !isset($refs[$ref])) $errors[] = "{$date} meals[$j]: dish_ref «{$ref}» не описано в new_dishes";
            }
        }

        return $errors;
    }

    /** Виконати погоджене. Повертає підсумок для звіту. */
    public function apply(array $p): array
    {
        return DB::transaction(function () use ($p) {
            $order = Order::findOrFail($p['order_id']);
            $byRef = [];
            $created = [];
            $reused = [];

            foreach ((array) ($p['new_dishes'] ?? []) as $d) {
                // Повторна подача тієї ж страви не плодить дублі в довіднику.
                $dish = Dish::where('name', $d['name'])->first();
                if ($dish) {
                    $reused[] = $dish->name;
                } else {
                    $dish = Dish::create([
                        'name'          => $d['name'],
                        'group'         => $d['group'] ?? null,
                        'base_weight_g' => $d['base_weight_g'] ?? null,
                        'description'   => $d['description'] ?? null,
                    ]);
                    foreach ($d['ingredients'] as $ing) {
                        DishIngredient::create([
                            'dish_id'       => $dish->id,
                            'type'          => !empty($ing['child_dish_id']) ? 'pf' : 'product',
                            'ingredient_id' => $ing['ingredient_id'] ?? null,
                            'child_dish_id' => $ing['child_dish_id'] ?? null,
                            'net_weight_g'  => $ing['net_weight_g'],
                        ]);
                    }
                    $created[] = $dish->name;
                }
                $byRef[$d['ref']] = $dish->id;
            }

            $rows = 0;
            foreach ($p['days'] as $day) {
                $date = Carbon::parse($day['date'])->toDateString();
                if ($p['replace_days'] ?? true) {
                    OrderDayDish::where('order_id', $order->id)->whereDate('date', $date)->delete();
                }
                foreach ($day['meals'] as $m) {
                    OrderDayDish::where('order_id', $order->id)->whereDate('date', $date)
                        ->where('meal_type_id', $m['meal_type_id'])->delete();
                    OrderDayDish::create([
                        'order_id'     => $order->id,
                        'date'         => $date,
                        'meal_type_id' => $m['meal_type_id'],
                        'dish_id'      => $m['dish_id'] ?? $byRef[$m['dish_ref']],
                        'weight_grams' => $m['weight_grams'] ?? null,   // порожньо — CRM рахує під калораж
                        'cooking_note' => $m['cooking_note'] ?? null,
                    ]);
                    $rows++;
                }
            }

            return [
                'order_id'       => $order->id,
                'dishes_created' => $created,
                'dishes_reused'  => $reused,
                'menu_rows'      => $rows,
                'dates'          => array_column($p['days'], 'date'),
            ];
        });
    }

    /** Короткий опис для людини (Telegram/CRM). */
    public function describe(array $p): string
    {
        $order = Order::with('client')->find($p['order_id'] ?? null);
        $dates = array_column((array) ($p['days'] ?? []), 'date');
        $meals = array_sum(array_map(fn ($d) => count($d['meals'] ?? []), (array) ($p['days'] ?? [])));

        return trim(($order?->client?->name ?? 'Клієнт ?') . " (#{$order?->id})\n"
            . ($dates ? Carbon::parse(min($dates))->format('d.m') . '–' . Carbon::parse(max($dates))->format('d.m') : '')
            . ", {$meals} прийомів" . (count($p['new_dishes'] ?? []) ? ', нових страв: ' . count($p['new_dishes']) : ''));
    }
}
