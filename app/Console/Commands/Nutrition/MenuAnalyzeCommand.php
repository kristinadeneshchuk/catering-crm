<?php

namespace App\Console\Commands\Nutrition;

use App\Models\Client;
use App\Models\DailyMenu;
use Illuminate\Console\Command;

/**
 * READ-ONLY. Diagnostic for a daily menu against KBZHU targets.
 * With --client, scales the menu to client's target_kcal and flags conflicts.
 */
class MenuAnalyzeCommand extends Command
{
    protected $signature = 'nutrition:menu:analyze
        {id? : DailyMenu ID (або використовуй --plan + --day)}
        {--plan= : Назва або ID плану меню}
        {--day= : Номер дня циклу}
        {--client= : Client ID для масштабування і пошуку конфліктів}
        {--json : Machine-readable output}';

    protected $description = 'Read-only: deviations from target by KBZHU and per-meal, conflicts with client allergens/exclusions';

    public function handle(): int
    {
        $menu = $this->resolveMenu();
        if (!$menu instanceof DailyMenu) {
            return self::FAILURE;
        }

        $client = null;
        $clientMealTypeIds = null;
        $clientMealTypeEnergy = [];
        $excludedIngrIds = [];
        $excludedDishIds = [];

        if ($cid = $this->option('client')) {
            $client = Client::with([
                'ingredientExclusions:id',
                'dishExclusions:id',
                'replacementBundles.items',
            ])->find($cid);
            if (!$client) {
                $this->error("Клієнт #{$cid} не знайдено.");
                return self::FAILURE;
            }

            foreach ($client->mealTypes()->withPivot('energy_percent')->get() as $mt) {
                $clientMealTypeEnergy[$mt->id] = (float) ($mt->pivot->energy_percent ?? $mt->energy_percent);
            }
            $clientMealTypeIds = array_keys($clientMealTypeEnergy);
            $excludedIngrIds = $client->effectiveExcludedIngredientIds();
            $excludedDishIds = $client->dishExclusions->pluck('id')->all();
        }

        $mealsRaw = [];
        foreach ($menu->menuItems as $item) {
            if (!$item->mealType || !$item->dish) continue;
            if ($clientMealTypeIds !== null && !in_array($item->mealType->id, $clientMealTypeIds, true)) continue;

            $mtId = $item->mealType->id;
            if (!isset($mealsRaw[$mtId])) {
                $mealsRaw[$mtId] = [
                    'meal_type_id'   => $mtId,
                    'meal_type'      => $item->mealType->name,
                    'sort_order'     => (int) $item->mealType->sort_order,
                    'energy_percent' => $clientMealTypeEnergy[$mtId] ?? (float) $item->mealType->energy_percent,
                    'kcal' => 0.0, 'prot' => 0.0, 'fat' => 0.0, 'carb' => 0.0,
                    'dishes' => [],
                ];
            }
            $t = $item->dish->calculated_totals;
            $mealsRaw[$mtId]['kcal'] += (float)$t['kcal'];
            $mealsRaw[$mtId]['prot'] += (float)$t['prot'];
            $mealsRaw[$mtId]['fat']  += (float)$t['fat'];
            $mealsRaw[$mtId]['carb'] += (float)$t['carb'];
            $mealsRaw[$mtId]['dishes'][] = [
                'id'   => $item->dish->id,
                'name' => $item->dish->name ?? null,
                'kcal' => round((float)$t['kcal'], 1),
                'prot' => round((float)$t['prot'], 1),
                'fat'  => round((float)$t['fat'], 1),
                'carb' => round((float)$t['carb'], 1),
            ];
        }
        usort($mealsRaw, fn($a, $b) => $a['sort_order'] <=> $b['sort_order']);

        $baseKcal = array_sum(array_column($mealsRaw, 'kcal'));
        $baseProt = array_sum(array_column($mealsRaw, 'prot'));
        $baseFat  = array_sum(array_column($mealsRaw, 'fat'));
        $baseCarb = array_sum(array_column($mealsRaw, 'carb'));

        if ($client && $client->target_kcal && $baseKcal > 0) {
            $scale = ((float) $client->target_kcal) / $baseKcal;
            $ratio = ((float) $client->target_kcal) / max(1, (float) $menu->target_kcal);
            $target = [
                'kcal'   => (int) $client->target_kcal,
                'prot'   => round(((int) $menu->target_protein_g) * $ratio, 1),
                'fat'    => round(((int) $menu->target_fat_g)     * $ratio, 1),
                'carb'   => round(((int) $menu->target_carb_g)    * $ratio, 1),
                'source' => 'client',
            ];
        } else {
            $scale = 1.0;
            $target = [
                'kcal'   => (int) $menu->target_kcal,
                'prot'   => (int) $menu->target_protein_g,
                'fat'    => (int) $menu->target_fat_g,
                'carb'   => (int) $menu->target_carb_g,
                'source' => 'menu',
            ];
        }

        $effective = [
            'kcal' => round($baseKcal * $scale, 1),
            'prot' => round($baseProt * $scale, 1),
            'fat'  => round($baseFat  * $scale, 1),
            'carb' => round($baseCarb * $scale, 1),
            'scale_factor' => round($scale, 4),
        ];

        $perMeal = [];
        foreach ($mealsRaw as $m) {
            $mealTargetKcal = ($target['kcal'] * $m['energy_percent']) / 100.0;
            $mealActualKcal = $m['kcal'] * $scale;
            $perMeal[] = [
                'meal_type'         => $m['meal_type'],
                'meal_type_id'      => $m['meal_type_id'],
                'energy_percent'    => $m['energy_percent'],
                'target_kcal'       => round($mealTargetKcal, 1),
                'actual_kcal'       => round($mealActualKcal, 1),
                'delta_kcal'        => round($mealActualKcal - $mealTargetKcal, 1),
                'delta_kcal_percent'=> $mealTargetKcal > 0 ? round(($mealActualKcal - $mealTargetKcal) / $mealTargetKcal * 100, 1) : null,
                'actual_prot'       => round($m['prot'] * $scale, 1),
                'actual_fat'        => round($m['fat']  * $scale, 1),
                'actual_carb'       => round($m['carb'] * $scale, 1),
                'dishes'            => $m['dishes'],
            ];
        }

        $deviations = [
            'kcal' => ['delta' => round($effective['kcal'] - $target['kcal'], 1), 'pct' => $target['kcal'] ? round(($effective['kcal'] - $target['kcal']) / $target['kcal'] * 100, 1) : null],
            'prot' => ['delta' => round($effective['prot'] - $target['prot'], 1), 'pct' => $target['prot'] ? round(($effective['prot'] - $target['prot']) / $target['prot'] * 100, 1) : null],
            'fat'  => ['delta' => round($effective['fat']  - $target['fat'],  1), 'pct' => $target['fat']  ? round(($effective['fat']  - $target['fat'])  / $target['fat']  * 100, 1) : null],
            'carb' => ['delta' => round($effective['carb'] - $target['carb'], 1), 'pct' => $target['carb'] ? round(($effective['carb'] - $target['carb']) / $target['carb'] * 100, 1) : null],
        ];

        $conflicts = ['allergens' => [], 'excluded_ingredients' => [], 'excluded_dishes' => []];
        if ($client) {
            $bundleMap = [];
            foreach ($client->replacementBundles as $b) {
                foreach ($b->items as $bi) {
                    if ($bi->original_ingredient_id) $bundleMap[$bi->original_ingredient_id] = $bi->replacement_ingredient_id;
                }
            }

            foreach ($menu->menuItems as $item) {
                if (!$item->dish) continue;
                if ($clientMealTypeIds !== null && !in_array($item->mealType?->id, $clientMealTypeIds, true)) continue;

                if (in_array($item->dish->id, $excludedDishIds, true)) {
                    $conflicts['excluded_dishes'][] = ['dish_id' => $item->dish->id, 'name' => $item->dish->name ?? null];
                }

                foreach ($this->collectIngredientIds($item->dish) as $ingId => $ingName) {
                    if (in_array($ingId, $excludedIngrIds, true)) {
                        $conflicts['excluded_ingredients'][] = [
                            'dish_id'         => $item->dish->id,
                            'dish_name'       => $item->dish->name ?? null,
                            'ingredient_id'   => $ingId,
                            'ingredient_name' => $ingName,
                            'replacement_id'  => $bundleMap[$ingId] ?? null,
                        ];
                    }
                }
            }

            $confIngIds = array_unique(array_column($conflicts['excluded_ingredients'], 'ingredient_id'));
            if (!empty($confIngIds)) {
                $rows = \App\Models\Ingredient::with('allergens:id,name')->whereIn('id', $confIngIds)->get();
                foreach ($rows as $r) {
                    foreach ($r->allergens as $a) {
                        $conflicts['allergens'][] = ['ingredient_id' => $r->id, 'ingredient_name' => $r->name, 'allergen' => $a->name];
                    }
                }
            }
        }

        $payload = [
            'menu_id'      => $menu->id,
            'menu_plan_id' => $menu->menu_plan_id,
            'day_number'   => $menu->day_number,
            'target'       => $target,
            'effective'    => $effective,
            'deviations'   => $deviations,
            'meals'        => $perMeal,
            'conflicts'    => $conflicts,
            'client'       => $client ? ['id' => $client->id, 'name' => $client->name, 'target_kcal' => $client->target_kcal] : null,
        ];

        if ($this->option('json')) {
            $this->line(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            return self::SUCCESS;
        }

        $this->info("Аналіз DailyMenu #{$menu->id}" . ($client ? " під клієнта #{$client->id} ({$client->name})" : ''));
        $this->line(sprintf('  Ціль [%s]:    ккал %s | Б %s | Ж %s | В %s', $target['source'], $target['kcal'], $target['prot'], $target['fat'], $target['carb']));
        $this->line(sprintf('  Ефективно:   ккал %.1f | Б %.1f | Ж %.1f | В %.1f (scale %.3f)', $effective['kcal'], $effective['prot'], $effective['fat'], $effective['carb'], $effective['scale_factor']));
        $this->line(sprintf('  Дельта:      %s%.1f ккал (%s%.1f%%) | Б %s%.1f (%s%.1f%%) | Ж %s%.1f (%s%.1f%%) | В %s%.1f (%s%.1f%%)',
            $deviations['kcal']['delta'] >= 0 ? '+' : '', $deviations['kcal']['delta'], $deviations['kcal']['pct'] >= 0 ? '+' : '', $deviations['kcal']['pct'] ?? 0,
            $deviations['prot']['delta'] >= 0 ? '+' : '', $deviations['prot']['delta'], $deviations['prot']['pct'] >= 0 ? '+' : '', $deviations['prot']['pct'] ?? 0,
            $deviations['fat']['delta']  >= 0 ? '+' : '', $deviations['fat']['delta'],  $deviations['fat']['pct']  >= 0 ? '+' : '', $deviations['fat']['pct']  ?? 0,
            $deviations['carb']['delta'] >= 0 ? '+' : '', $deviations['carb']['delta'], $deviations['carb']['pct'] >= 0 ? '+' : '', $deviations['carb']['pct'] ?? 0,
        ));

        $this->line('');
        $this->info('По прийомах:');
        foreach ($perMeal as $m) {
            $sign = $m['delta_kcal'] >= 0 ? '+' : '';
            $this->line(sprintf('  %s [%.1f%%]: ціль %.1f ккал, факт %.1f ккал (%s%.1f, %s%.1f%%) | Б %.1f / Ж %.1f / В %.1f',
                $m['meal_type'], $m['energy_percent'], $m['target_kcal'], $m['actual_kcal'],
                $sign, $m['delta_kcal'], $sign, $m['delta_kcal_percent'] ?? 0,
                $m['actual_prot'], $m['actual_fat'], $m['actual_carb']));
        }

        if ($client) {
            $this->line('');
            $this->info('Конфлікти:');
            if (empty($conflicts['excluded_ingredients']) && empty($conflicts['excluded_dishes'])) {
                $this->line('  (немає)');
            } else {
                foreach ($conflicts['excluded_dishes'] as $d) {
                    $this->warn("  Страва #{$d['dish_id']} ({$d['name']}) — у винятках клієнта");
                }
                foreach ($conflicts['excluded_ingredients'] as $c) {
                    $tail = $c['replacement_id'] ? " → є заміна у бандлі (ingredient #{$c['replacement_id']})" : '';
                    $this->warn("  У страві #{$c['dish_id']} ({$c['dish_name']}): інгредієнт #{$c['ingredient_id']} {$c['ingredient_name']}{$tail}");
                }
                if (!empty($conflicts['allergens'])) {
                    $this->line('');
                    $this->info('Алергени, спровоковані конфліктними інгредієнтами:');
                    foreach (array_unique(array_column($conflicts['allergens'], 'allergen')) as $a) {
                        $this->line("  — {$a}");
                    }
                }
            }
        }

        return self::SUCCESS;
    }

    private function collectIngredientIds($dish): array
    {
        $out = [];
        foreach ($dish->dishIngredients as $di) {
            if ($di->ingredient) {
                $out[$di->ingredient->id] = $di->ingredient->name;
            }
            if ($di->childDish) {
                foreach ($di->childDish->dishIngredients as $sub) {
                    if ($sub->ingredient) {
                        $out[$sub->ingredient->id] = $sub->ingredient->name;
                    }
                }
            }
        }
        return $out;
    }

    private function resolveMenu(): DailyMenu|false
    {
        $with = [
            'menuItems.mealType',
            'menuItems.dish.dishIngredients.ingredient.allergens:id,name',
            'menuItems.dish.dishIngredients.childDish.dishIngredients.ingredient.allergens:id,name',
        ];

        if ($id = $this->argument('id')) {
            $menu = DailyMenu::with($with)->find((int) $id);
            if (!$menu) { $this->error("DailyMenu #{$id} не знайдено."); return false; }
            return $menu;
        }

        $plan = trim((string) $this->option('plan'));
        $day  = $this->option('day');
        if (!$plan || !$day) {
            $this->error('Вкажи DailyMenu ID АБО --plan="..." і --day=N.');
            return false;
        }

        if (is_numeric($plan)) {
            $menuPlan = \App\Models\MenuPlan::find((int) $plan);
            if (!$menuPlan) {
                $this->error("План #{$plan} не знайдено.");
                return false;
            }
        } else {
            $candidates = \App\Models\MenuPlan::where('name', 'like', '%' . $plan . '%')
                ->whereHas('dailyMenus', fn($q) => $q->where('day_number', (int) $day))
                ->get();
            if ($candidates->count() > 1) {
                $this->warn("Кілька планів «{$plan}» мають день {$day}:");
                foreach ($candidates as $p) {
                    $this->line("  #{$p->id} — {$p->name} (cycle_days={$p->cycle_days})");
                }
                $this->error('Уточни план по ID.');
                return false;
            }
            if ($candidates->count() === 1) {
                $menuPlan = $candidates->first();
            } else {
                $all = \App\Models\MenuPlan::where('name', 'like', '%' . $plan . '%')->get();
                if ($all->isEmpty()) {
                    $this->error("План «{$plan}» не знайдено.");
                } else {
                    $this->warn("План «{$plan}» знайдено, але без дня {$day}:");
                    foreach ($all as $p) {
                        $this->line("  #{$p->id} — {$p->name} (cycle_days={$p->cycle_days})");
                    }
                }
                return false;
            }
        }

        $menu = DailyMenu::with($with)
            ->where('menu_plan_id', $menuPlan->id)
            ->where('day_number', (int) $day)
            ->first();
        if (!$menu) {
            $this->error("У плані «{$menuPlan->name}» (id {$menuPlan->id}) немає дня {$day}.");
            return false;
        }
        return $menu;
    }
}
