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
        $typical = self::typicalGrams(collect($p['new_dishes'] ?? [])->flatMap(fn ($d) => array_column((array) ($d['ingredients'] ?? []), 'ingredient_id'))->filter()->all());
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
                $g = (float) ($ing['net_weight_g'] ?? 0);
                if ($g <= 0) $errors[] = "new_dishes[$i].ingredients[$j]: net_weight_g > 0";
                // Грами на порцію, не кг і не грами на партію: 0,15 замість 150 чи 1500
                // замість 150 перекошують собівартість і закупку в тисячу разів.
                elseif (($base = (float) ($d['base_weight_g'] ?? 0)) > 0 && $g > $base * 2.5)
                    $errors[] = "new_dishes[$i].ingredients[$j]: {$g} г при виході страви {$base} г — net_weight_g у грамах на одну порцію";
                elseif ($id && ($med = $typical[$id] ?? null) && $med >= 20 && $g < $med / 50)
                    $errors[] = "new_dishes[$i].ingredients[$j]: {$g} г, а зазвичай ~{$med} г — схоже, кілограми замість грамів";
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

    /**
     * Типова вага продукту в техкартах (медіана грамів на рядок) — щоб ловити
     * кілограми, записані замість грамів.
     *
     * @return array<int, float>
     */
    private static function typicalGrams(array $ingredientIds): array
    {
        if (!$ingredientIds) return [];

        return DishIngredient::whereIn('ingredient_id', array_unique($ingredientIds))->where('net_weight_g', '>', 0)
            ->get(['ingredient_id', 'net_weight_g'])->groupBy('ingredient_id')
            ->filter(fn ($rows) => $rows->count() >= 4)
            ->map(fn ($rows) => (float) $rows->pluck('net_weight_g')->sort()->values()[intdiv($rows->count(), 2)])
            ->all();
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

    /** Скільки днів страва не має повторюватись, якщо клієнт сам не просить. */
    public const NO_REPEAT_DAYS = 28;

    /**
     * Повтори страв: за 4 тижні до меню (усі замовлення клієнта) і всередині
     * самого меню. Не блокує — погоджувач бачить і вирішує. Страви з
     * repeat_ok (id або ref) — клієнт сам просить, їх не показуємо.
     *
     * @return string[]
     */
    public function warnings(array $p): array
    {
        $order = Order::find($p['order_id'] ?? null);
        $days = collect($p['days'] ?? [])->sortBy('date')->values();
        if (!$order || $days->isEmpty()) return [];

        $ok = collect((array) ($p['repeat_ok'] ?? []))->map(fn ($v) => (string) $v)->all();
        $refNames = collect($p['new_dishes'] ?? [])->mapWithKeys(fn ($d) => [$d['ref'] => $d['name']]);
        $existingByName = Dish::whereIn('name', $refNames->values())->pluck('id', 'name');

        // Що в меню: ключ — id страви (для нових, яких ще немає, — «ref:…»).
        $planned = [];
        $names = [];
        foreach ($days as $day) {
            foreach ($day['meals'] ?? [] as $m) {
                $ref = $m['dish_ref'] ?? null;
                if ($ref !== null && in_array((string) $ref, $ok, true)) continue;
                $id = $m['dish_id'] ?? ($existingByName[$refNames[$ref] ?? ''] ?? null);
                if ($id !== null && in_array((string) $id, $ok, true)) continue;
                $key = $id ? (string) $id : 'ref:' . $ref;
                $planned[$key][] = $day['date'];
                $names[$key] = $refNames[$ref] ?? null;
            }
        }

        $first = Carbon::parse($days->first()['date']);
        $history = OrderDayDish::query()
            ->whereIn('order_id', Order::where('client_id', $order->client_id)->pluck('id'))
            ->whereDate('date', '>=', $first->copy()->subDays(self::NO_REPEAT_DAYS))
            ->whereDate('date', '<', $first)
            ->whereIn('dish_id', collect(array_keys($planned))->filter(fn ($k) => ctype_digit((string) $k)))
            ->get(['dish_id', 'date'])
            ->groupBy('dish_id');

        $dishNames = Dish::whereIn('id', collect(array_keys($planned))->filter(fn ($k) => ctype_digit((string) $k)))->pluck('name', 'id');
        $out = [];
        foreach ($planned as $key => $dates) {
            $past = ctype_digit((string) $key) ? ($history[(int) $key] ?? collect())->map(fn ($r) => Carbon::parse($r->date)->format('d.m'))->unique()->values()->all() : [];
            $dup = count($dates) > 1;
            if (!$past && !$dup) continue;
            $name = $dishNames[(int) $key] ?? $names[$key] ?? $key;
            $parts = [];
            if ($past) $parts[] = 'вже була ' . implode(', ', $past);
            if ($dup) $parts[] = 'у цьому меню ' . count($dates) . ' рази (' . implode(', ', array_map(fn ($d) => Carbon::parse($d)->format('d.m'), $dates)) . ')';
            $out[] = "Повтор: {$name} — " . implode('; ', $parts);
        }

        return array_merge($out, $this->kitchenLoadWarnings($p), $this->costWarnings($p));
    }

    /**
     * Навантаження кухні: скільки окремих страв поза циклом на день по всіх
     * індивідуальних разом (з цим меню замість поточного меню цього замовлення).
     *
     * @return string[]
     */
    public function kitchenLoadWarnings(array $p): array
    {
        $max = (int) config('services.ai_agents.kitchen_offcycle_max', 6);
        $cycle = \Illuminate\Support\Facades\DB::table('daily_menu_dishes')->distinct()->pluck('dish_id')->flip();
        $refNames = collect($p['new_dishes'] ?? [])->mapWithKeys(fn ($d) => [$d['ref'] => $d['name']]);
        $out = [];
        foreach ((array) ($p['days'] ?? []) as $day) {
            $date = $day['date'];
            $others = OrderDayDish::whereDate('date', $date)->where('order_id', '!=', $p['order_id'] ?? 0)->pluck('dish_id')
                ->reject(fn ($id) => $cycle->has($id))->map(fn ($id) => (string) $id);
            $mine = collect($day['meals'] ?? [])->map(fn ($m) => !empty($m['dish_id']) ? (string) $m['dish_id'] : 'new:' . ($refNames[$m['dish_ref'] ?? ''] ?? $m['dish_ref'] ?? ''))
                ->reject(fn ($k) => ctype_digit($k) && $cycle->has((int) $k));
            $total = $others->merge($mine)->unique()->count();
            if ($total > $max) {
                $out[] = 'Навантаження кухні ' . Carbon::parse($date)->format('d.m') . ": {$total} окремих страв поза циклом (ліміт {$max}) — краще взяти страви, які вже готуються іншим";
            }
        }

        return $out;
    }

    /**
     * Собівартість продуктів по днях проти ціни дня клієнта. Межа —
     * FOOD_COST_MAX_PCT (30%): 1100 грн/день → ≤ 330. Вагу без weight_grams
     * підганяємо під калораж замовлення, як CRM. Орієнтовно: ціни з карток.
     *
     * @return array<int, array{date: string, cost: float, price: float, pct: float}>
     */
    public function costs(array $p): array
    {
        return array_map(fn ($r) => ['date' => $r['date'], 'cost' => $r['cost'], 'price' => $r['price'], 'pct' => $r['pct']], $this->dayTotals($p));
    }

    /**
     * Що вийде за день: ккал, БЖВ і собівартість з техкарт CRM, з вагою під
     * калораж замовлення для страв без weight_grams (як рахує сама CRM).
     *
     * @return array<int, array{date: string, kcal: float, p: float, f: float, c: float, cost: float, price: float, pct: float}>
     */
    public function dayTotals(array $p): array
    {
        $order = Order::find($p['order_id'] ?? null);
        if (!$order) return [];
        $price = (float) ($order->price_per_day ?: ($order->duration ? $order->final_price / $order->duration : 0));
        $kcalTarget = (float) $order->calories;

        Ingredient::preloadAveragePrices();
        $new = collect($p['new_dishes'] ?? [])->keyBy('ref')->map(fn ($d) => $this->draftDish($d));
        $ids = collect($p['days'] ?? [])->flatMap(fn ($d) => collect($d['meals'] ?? [])->pluck('dish_id'))->filter()->unique();
        $dishes = Dish::with(['dishIngredients.ingredient', 'dishIngredients.childDish.dishIngredients.ingredient'])->whereIn('id', $ids)->get()->keyBy('id');

        $out = [];
        foreach ((array) ($p['days'] ?? []) as $day) {
            $fixed = ['kcal' => 0.0, 'prot' => 0.0, 'fat' => 0.0, 'carb' => 0.0, 'cost' => 0.0];
            $free = $fixed;
            foreach ($day['meals'] ?? [] as $m) {
                $dish = !empty($m['dish_id']) ? ($dishes[$m['dish_id']] ?? null) : ($new[$m['dish_ref'] ?? ''] ?? null);
                if (!$dish) continue;
                $t = $dish->calculated_totals;
                $w = (float) ($t['output_weight'] ?: 1);
                $k = !empty($m['weight_grams']) ? $m['weight_grams'] / $w : 1.0;
                foreach (array_keys($fixed) as $key) {
                    if (!empty($m['weight_grams'])) $fixed[$key] += $t[$key] * $k;
                    else $free[$key] += $t[$key];
                }
            }
            // Страви «під калораж» масштабуємо, щоб день дав калораж замовлення.
            $scale = ($free['kcal'] > 0 && $kcalTarget > 0) ? max(0.3, min(3.0, ($kcalTarget - $fixed['kcal']) / $free['kcal'])) : 1.0;
            $sum = fn (string $key) => round($fixed[$key] + $free[$key] * $scale, 1);
            $cost = round($fixed['cost'] + $free['cost'] * $scale, 2);
            $out[] = [
                'date' => $day['date'], 'kcal' => $sum('kcal'), 'p' => $sum('prot'), 'f' => $sum('fat'), 'c' => $sum('carb'),
                'cost' => $cost, 'price' => round($price, 2), 'pct' => $price > 0 ? round($cost / $price * 100, 1) : 0.0,
            ];
        }

        return $out;
    }

    /** @return string[] */
    private function costWarnings(array $p): array
    {
        $rows = $this->costs($p);
        if (!$rows || !$rows[0]['price']) return [];
        $max = (float) config('services.ai_agents.food_cost_max_pct', 30);
        $out = [];
        foreach ($rows as $r) {
            if ($r['pct'] > $max) {
                $out[] = 'Собівартість ' . Carbon::parse($r['date'])->format('d.m') . ' ≈ ' . round($r['cost']) . ' грн = ' . $r['pct']
                    . "% ціни дня (" . round($r['price']) . "), норма ≤ {$max}% (≤ " . round($r['price'] * $max / 100) . ' грн)';
            }
        }
        $avg = round(array_sum(array_column($rows, 'cost')) / count($rows));
        $avgPct = round($avg / $rows[0]['price'] * 100, 1);
        if ($avgPct > $max || $out) array_unshift($out, "Собівартість у середньому ≈ {$avg} грн/день = {$avgPct}% (норма ≤ {$max}%)");

        return $out;
    }

    /** Нова страва в памʼяті — щоб порахувати її без запису в довідник. */
    private function draftDish(array $d): Dish
    {
        $dish = new Dish(['name' => $d['name'] ?? '', 'base_weight_g' => $d['base_weight_g'] ?? null]);
        $items = collect($d['ingredients'] ?? [])->map(function ($ing) {
            $di = new DishIngredient([
                'type' => !empty($ing['child_dish_id']) ? 'pf' : 'product',
                'ingredient_id' => $ing['ingredient_id'] ?? null,
                'child_dish_id' => $ing['child_dish_id'] ?? null,
                'net_weight_g' => $ing['net_weight_g'] ?? 0,
            ]);
            $di->setRelation('ingredient', !empty($ing['ingredient_id']) ? Ingredient::find($ing['ingredient_id']) : null);
            $di->setRelation('childDish', !empty($ing['child_dish_id']) ? Dish::with('dishIngredients.ingredient')->find($ing['child_dish_id']) : null);

            return $di;
        });
        $dish->setRelation('dishIngredients', $items);

        return $dish;
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
