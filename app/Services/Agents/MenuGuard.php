<?php

namespace App\Services\Agents;

use App\Models\ClientDietProfile;
use App\Models\Ingredient;
use App\Models\IngredientGroup;
use App\Models\Order;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Кодові перевірки індивідуального меню до того, як його побачить людина
 * (docs/tz-ai-agents-in-crm.md §4). Модель може помилитись — код ні:
 *
 *  V1  виключення клієнта на всю глибину напівфабрикатів (+ заміни замовлення);
 *  V2  групи-синоніми з профілю («кукурудза» = зерно, крохмаль, мексиканські овочі);
 *  V3  дата — день доставки замовлення (не пауза, не поза замовленням);
 *  V4  «битий» продукт у довіднику: 0 ккал або ккал ≠ 4Б+9Ж+4В;
 *  V5  КБЖВ дня проти цілі замовлення;
 *  V9  правила профілю: «не частіше N на день/тиждень», «краще ні»;
 *  V10 дедлайн: меню на D — до 10:00 D−1;
 *  V6–V8 (собівартість, повтори, навантаження кухні) — з IndividualWeekMenu::warnings.
 *
 * error — пропозиція не приймається (агент виправляє), warn — бейдж для людини.
 */
class MenuGuard
{
    private const MAX_DEPTH = 8;

    /** Продукти, яким 0 ккал — норма, навіть поза групою zero_kcal_ok. */
    private const ZERO_KCAL_NAMES = '/(^|\s)(сіль|вода|спеці|прянощ|лавров|оцет|перець\s+(чорн|мелен|духм)|сода|дріжджі)/iu';

    /** @var array<int, array<int, array{ingredient_id: ?int, child_dish_id: ?int, net: float}>> */
    private array $rows = [];

    /** @var array<int, string> */
    private array $dishNames = [];

    /**
     * @return array<int, array{code: string, level: string, text: string}>
     */
    public function check(array $p): array
    {
        $order = Order::with(['client.ingredientExclusions', 'client.dishExclusions', 'client.replacementBundles.items.originalIngredient', 'ingredientExclusions'])
            ->find($p['order_id'] ?? null);
        if (!$order) return [];

        $profile = ClientDietProfile::where('client_id', $order->client_id)->first();
        $groups = IngredientGroup::map();
        $days = collect($p['days'] ?? [])->sortBy('date')->values()->all();
        $newDishes = collect($p['new_dishes'] ?? [])->keyBy('ref');

        // Склад кожної страви меню до сирого продукту.
        $dishIds = collect($days)->flatMap(fn ($d) => collect($d['meals'] ?? [])->pluck('dish_id'))->filter()->map(fn ($id) => (int) $id)->unique()->all();
        $replacements = DB::table('order_replacements')->where('order_id', $order->id)->get();
        $this->load(array_merge($dishIds, $replacements->pluck('replacement_dish_id')->filter()->all(),
            $newDishes->flatMap(fn ($d) => collect($d['ingredients'] ?? [])->pluck('child_dish_id'))->filter()->all()));

        $checks = [];
        $mealNames = DB::table('meal_types')->pluck('name', 'id');
        $excluded = $this->excludedIngredients($order, $profile, $groups);
        $excludedDishes = $order->client?->dishExclusions?->pluck('name', 'id') ?? collect();
        $deliveryDates = DB::table('order_days')->where('order_id', $order->id)
            ->get(['date', 'delivery_date_override'])
            ->map(fn ($r) => substr((string) $r->date, 0, 10))->flip();

        $leavesByMeal = [];
        foreach ($days as $day) {
            $date = (string) ($day['date'] ?? '');
            $label = $this->dateLabel($date);

            // V3 — день доставки замовлення.
            if (!$deliveryDates->has($date)) {
                $checks[] = $this->c('V3', 'error', "{$label}: такого дня доставки в замовленні немає (пауза або поза датами) — меню на цей день не подавати");
            }

            foreach ($day['meals'] ?? [] as $j => $m) {
                $meal = $mealNames[$m['meal_type_id'] ?? 0] ?? 'прийом';
                $dishId = !empty($m['dish_id']) ? (int) $m['dish_id'] : null;
                $name = $dishId ? ($this->dishNames[$dishId] ?? "#{$dishId}") : ($newDishes[$m['dish_ref'] ?? '']['name'] ?? ($m['dish_ref'] ?? '?'));

                if ($dishId && $excludedDishes->has($dishId)) {
                    $checks[] = $this->c('V1', 'error', "{$label} {$meal}: «{$name}» — клієнт не їсть цю страву");
                }

                $leaves = $dishId
                    ? $this->leavesWithReplacements($dishId, $replacements)
                    : $this->leavesOfDraft((array) ($newDishes[$m['dish_ref'] ?? '']['ingredients'] ?? []));
                $leavesByMeal[$date][$j] = $leaves;

                // V1/V2 — виключені продукти на будь-якій глибині.
                foreach ($leaves as $ingId => $via) {
                    if (!isset($excluded[$ingId])) continue;
                    $where = $via ? ' (через ПФ «' . $via . '»)' : '';
                    $checks[] = $this->c($excluded[$ingId]['group'] ? 'V2' : 'V1', 'error',
                        "{$label} {$meal}: «{$name}» містить «{$excluded[$ingId]['name']}»{$where} — " . ($excluded[$ingId]['group'] ? "у групі «{$excluded[$ingId]['group']}», " : '')
                        . 'клієнт не їсть. Замініть страву або додайте заміну продукту');
                }
            }
        }

        $allLeaves = collect($leavesByMeal)->flatten(1)->flatMap(fn ($l) => array_keys($l))->unique()->values()->all();
        $checks = array_merge($checks, $this->brokenIngredients($allLeaves, $groups));
        $checks = array_merge($checks, $this->nutrition($p, $order));
        if ($profile) $checks = array_merge($checks, $this->profileRules($profile, $days, $leavesByMeal, $groups));
        $checks = array_merge($checks, $this->deadlines($days));

        try {
            foreach (app(IndividualWeekMenu::class)->warnings($p) as $w) {
                $code = str_starts_with($w, 'Повтор') ? 'V7' : (str_starts_with($w, 'Навантаження') ? 'V8' : 'V6');
                $checks[] = $this->c($code, 'warn', $w);
            }
        } catch (Throwable $e) {
            report($e);
        }

        return $this->dedupe($checks);
    }

    /** @return string[] тексти жорстких помилок */
    public static function errors(array $checks): array
    {
        return array_values(array_map(fn ($c) => "[{$c['code']}] {$c['text']}", array_filter($checks, fn ($c) => $c['level'] === 'error')));
    }

    /** @return string[] тексти попереджень */
    public static function warnings(array $checks): array
    {
        return array_values(array_map(fn ($c) => "[{$c['code']}] {$c['text']}", array_filter($checks, fn ($c) => $c['level'] === 'warn')));
    }

    /**
     * Виключені продукти: клієнта, замовлення, з набору замін і з груп профілю.
     *
     * @return array<int, array{name: string, group: ?string}>
     */
    private function excludedIngredients(Order $order, ?ClientDietProfile $profile, array $groups): array
    {
        $out = [];
        foreach ($order->effectiveExcludedIngredients() as $ing) {
            $out[(int) $ing->id] = ['name' => (string) $ing->name, 'group' => null];
        }
        $groupNames = DB::table('ingredient_groups')->pluck('name', 'slug');
        $ids = [];
        foreach ((array) ($profile?->excluded_groups ?? []) as $slug) {
            foreach ($groups[$slug] ?? [] as $id) $ids[$id] = $groupNames[$slug] ?? $slug;
        }
        $names = $ids ? DB::table('ingredients')->whereIn('id', array_keys($ids))->pluck('name', 'id') : collect();
        foreach ($ids as $id => $group) {
            $out[$id] ??= ['name' => (string) ($names[$id] ?? "#{$id}"), 'group' => $group];
        }

        return $out;
    }

    /** Склад рядків dish_ingredients для страв і всіх їхніх ПФ — пачками, без запиту на кожен рівень кожної страви. */
    private function load(array $dishIds): void
    {
        $queue = array_values(array_diff(array_unique(array_map('intval', $dishIds)), array_keys($this->rows)));
        for ($depth = 0; $queue && $depth <= self::MAX_DEPTH; $depth++) {
            $rows = DB::table('dish_ingredients')->whereIn('dish_id', $queue)->get(['dish_id', 'ingredient_id', 'child_dish_id', 'net_weight_g']);
            foreach ($queue as $id) $this->rows[$id] = [];
            foreach ($rows as $r) {
                $this->rows[(int) $r->dish_id][] = [
                    'ingredient_id' => $r->ingredient_id ? (int) $r->ingredient_id : null,
                    'child_dish_id' => $r->child_dish_id ? (int) $r->child_dish_id : null,
                    'net' => (float) $r->net_weight_g,
                ];
            }
            $this->dishNames += DB::table('dishes')->whereIn('id', $queue)->pluck('name', 'id')->all();
            $queue = array_values(array_diff($rows->pluck('child_dish_id')->filter()->map(fn ($id) => (int) $id)->unique()->all(), array_keys($this->rows)));
        }
    }

    /**
     * Сирі продукти страви: id продукту → назва ПФ, через який він потрапив (або null, якщо напряму).
     *
     * @return array<int, ?string>
     */
    private function leaves(int $dishId, int $depth = 0, ?string $via = null, array $seen = []): array
    {
        if ($depth > self::MAX_DEPTH || isset($seen[$dishId])) return [];
        $seen[$dishId] = true;
        $out = [];
        foreach ($this->rows[$dishId] ?? [] as $r) {
            if ($r['ingredient_id']) $out[$r['ingredient_id']] ??= $via;
            if ($r['child_dish_id']) {
                $out += $this->leaves($r['child_dish_id'], $depth + 1, $via ?? ($this->dishNames[$r['child_dish_id']] ?? "#{$r['child_dish_id']}"), $seen);
            }
        }

        return $out;
    }

    /** Те саме з урахуванням замін замовлення: продукт → інший/прибрати, страва → інша. */
    private function leavesWithReplacements(int $dishId, $replacements): array
    {
        $forDish = $replacements->where('dish_id', $dishId);
        $wholeDish = $forDish->first(fn ($r) => $r->replacement_dish_id && !$r->original_product_id);
        if ($wholeDish) return $this->leaves((int) $wholeDish->replacement_dish_id);

        $leaves = $this->leaves($dishId);
        foreach ($forDish->where('original_product_id', '!=', null) as $r) {
            if (!array_key_exists((int) $r->original_product_id, $leaves)) continue;
            $via = $leaves[(int) $r->original_product_id];
            unset($leaves[(int) $r->original_product_id]);
            if ($r->replacement_product_id) $leaves[(int) $r->replacement_product_id] ??= $via;
        }

        return $leaves;
    }

    /** Склад нової страви з пропозиції (ще не в довіднику). */
    private function leavesOfDraft(array $ingredients): array
    {
        $out = [];
        foreach ($ingredients as $ing) {
            if (!empty($ing['ingredient_id'])) $out[(int) $ing['ingredient_id']] ??= null;
            if (!empty($ing['child_dish_id'])) {
                $id = (int) $ing['child_dish_id'];
                $out += $this->leaves($id, 1, $this->dishNames[$id] ?? "#{$id}");
            }
        }

        return $out;
    }

    /**
     * V4 — продукти, яким не можна вірити. CRM рахує ккал страви з БЖВ (4Б+9Ж+4В),
     * тож продукт без БЖВ дає страві «нуль», а БЖВ, що не сходяться з ккал у картці
     * (цвітна капуста з/м: 328 ккал), — криві ваги під калораж.
     */
    private function brokenIngredients(array $ids, array $groups): array
    {
        if (!$ids) return [];
        $zeroOk = array_flip($groups[IngredientGroup::ZERO_KCAL_OK] ?? []);
        $out = [];
        foreach (Ingredient::whereIn('id', $ids)->get() as $ing) {
            $kcal = (float) ($ing->calories_100g ?? 0);
            $calc = 4 * (float) ($ing->proteins_100g ?? 0) + 9 * (float) ($ing->fats_100g ?? 0) + 4 * (float) ($ing->carbs_100g ?? 0);
            if ($calc <= 0) {
                if (isset($zeroOk[$ing->id]) || preg_match(self::ZERO_KCAL_NAMES, (string) $ing->name)) continue;
                $out[] = $this->c('V4', 'error', "Продукт «{$ing->name}» #{$ing->id}: у довіднику немає БЖВ (0 ккал) — КБЖВ страви неправдиве. Візьміть іншу страву або попросіть виправити довідник");
            } elseif ($kcal > 0 && max($kcal, $calc) >= 30 && abs($kcal - $calc) / max($kcal, $calc) > 0.25) {
                $out[] = $this->c('V4', 'error', "Продукт «{$ing->name}» #{$ing->id}: у картці " . round($kcal) . ' ккал/100 г, а за БЖВ ' . round($calc)
                    . ' — довідник з помилкою. Візьміть іншу страву або попросіть виправити довідник');
            }
        }

        return $out;
    }

    /**
     * V5 — КБЖВ дня з техкарт CRM проти цілі замовлення. Лише для днів, де меню
     * закриває всі прийоми клієнта: у гібриді частина прийомів — цикл, і рахувати
     * день лише з персональних страв було б неправдою.
     */
    private function nutrition(array $p, Order $order): array
    {
        $targets = ['kcal' => (int) $order->calories, 'p' => (int) $order->target_protein_g, 'f' => (int) $order->target_fats_g, 'c' => (int) $order->target_carbs_g];
        $clientMeals = DB::table('client_meal_type')->where('client_id', $order->client_id)->pluck('meal_type_id')->map(fn ($id) => (int) $id)->all();
        if (!array_filter($targets) || !$clientMeals) return [];
        $fullDays = collect($p['days'] ?? [])
            ->filter(fn ($d) => !array_diff($clientMeals, collect($d['meals'] ?? [])->pluck('meal_type_id')->map(fn ($id) => (int) $id)->all()))
            ->pluck('date')->flip();
        if ($fullDays->isEmpty()) return [];
        $p['days'] = array_values(array_filter((array) $p['days'], fn ($d) => $fullDays->has($d['date'] ?? '')));
        $labels = ['kcal' => 'ккал', 'p' => 'білки', 'f' => 'жири', 'c' => 'вуглеводи'];
        $warn = (float) config('services.ai_agents.kbzhu_warn_pct', 10);
        $err = (float) config('services.ai_agents.kbzhu_error_pct', 20);

        try {
            $rows = app(IndividualWeekMenu::class)->dayTotals($p);
        } catch (Throwable $e) {
            report($e);

            return [];
        }

        $out = [];
        foreach ($rows as $r) {
            $parts = [];
            $level = null;
            foreach ($targets as $k => $t) {
                if ($t <= 0) continue;
                $pct = ($r[$k] - $t) / $t * 100;
                if (abs($pct) <= $warn) continue;
                $parts[] = $labels[$k] . ' ' . round($r[$k]) . " проти {$t} (" . ($pct > 0 ? '+' : '') . round($pct) . '%)';
                $level = (abs($pct) > $err || $level === 'error') ? 'error' : 'warn';
            }
            if ($parts) $out[] = $this->c('V5', $level, $this->dateLabel($r['date']) . ': ' . implode(', ', $parts));
        }

        return $out;
    }

    /**
     * V9 — правила профілю: rules [{kind: max_per_day|max_per_week, group_slug, value, text}]
     * і «краще ні» avoid [{ingredient_id}|{group_slug}].
     */
    private function profileRules(ClientDietProfile $profile, array $days, array $leavesByMeal, array $groups): array
    {
        $out = [];
        foreach ((array) $profile->rules as $rule) {
            $ids = array_flip($groups[$rule['group_slug'] ?? ''] ?? []);
            $max = (int) ($rule['value'] ?? 0);
            if (!$ids || $max < 0 || !in_array($rule['kind'] ?? '', ['max_per_day', 'max_per_week'], true)) continue;
            $text = $rule['text'] ?? ($rule['group_slug'] . ' не більше ' . $max);
            $perDay = [];
            foreach ($leavesByMeal as $date => $meals) {
                $perDay[$date] = count(array_filter($meals, fn ($leaves) => array_intersect_key($leaves, $ids)));
            }
            if (($rule['kind'] ?? '') === 'max_per_day') {
                foreach ($perDay as $date => $n) {
                    if ($n > $max) $out[] = $this->c('V9', 'warn', $this->dateLabel($date) . ": {$n} рази — правило клієнта «{$text}»");
                }
            } elseif (array_sum($perDay) > $max) {
                $out[] = $this->c('V9', 'warn', 'За меню ' . array_sum($perDay) . " рази — правило клієнта «{$text}»");
            }
        }

        $avoid = [];
        foreach ((array) $profile->avoid as $a) {
            if (!empty($a['ingredient_id'])) $avoid[(int) $a['ingredient_id']] = $a['text'] ?? null;
            foreach ($groups[$a['group_slug'] ?? ''] ?? [] as $id) $avoid[$id] = $a['text'] ?? $a['group_slug'];
        }
        if ($avoid) {
            $names = DB::table('ingredients')->whereIn('id', array_keys($avoid))->pluck('name', 'id');
            foreach ($leavesByMeal as $date => $meals) {
                foreach ($meals as $leaves) {
                    foreach (array_intersect_key($leaves, $avoid) as $id => $_) {
                        $out[] = $this->c('V9', 'warn', $this->dateLabel($date) . ': «' . ($names[$id] ?? "#{$id}") . '» — клієнт просив краще без цього');
                    }
                }
            }
        }

        return $out;
    }

    /** V10 — меню на D має бути в CRM до 10:00 D−1 (кухня готує напередодні). */
    private function deadlines(array $days): array
    {
        $hour = (int) config('services.ai_agents.menu_deadline_hour', 10);
        $late = [];
        foreach ($days as $day) {
            $date = Carbon::parse($day['date'] ?? 'now');
            if (now()->greaterThan($date->copy()->subDay()->setTime($hour, 0))) $late[] = $date->format('d.m');
        }

        return $late ? [$this->c('V10', 'warn', 'Після дедлайну (' . sprintf('%02d', $hour) . ':00 напередодні): ' . implode(', ', $late) . ' — узгодити зі старшим зміни')] : [];
    }

    private function dateLabel(string $date): string
    {
        try {
            return Carbon::parse($date)->format('d.m');
        } catch (Throwable) {
            return $date;
        }
    }

    private function c(string $code, string $level, string $text): array
    {
        return ['code' => $code, 'level' => $level, 'text' => $text];
    }

    private function dedupe(array $checks): array
    {
        return array_values(collect($checks)->unique(fn ($c) => $c['level'] . '|' . $c['text'])->all());
    }
}
