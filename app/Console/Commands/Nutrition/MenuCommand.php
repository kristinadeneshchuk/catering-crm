<?php

namespace App\Console\Commands\Nutrition;

use App\Models\DailyMenu;
use Illuminate\Console\Command;

/**
 * READ-ONLY. Daily-menu snapshot: dishes per meal type, target vs actual КБЖУ.
 */
class MenuCommand extends Command
{
    protected $signature = 'nutrition:menu
        {id? : DailyMenu ID (або використовуй --plan + --day)}
        {--plan= : Назва або ID плану меню}
        {--day= : Номер дня циклу}
        {--json : Machine-readable output}';

    protected $description = 'Read-only: daily menu snapshot with per-meal КБЖВ';

    public function handle(): int
    {
        $menu = $this->resolveMenu();
        if (!$menu instanceof DailyMenu) {
            return self::FAILURE;
        }

        $byMeal = [];
        $sumActual = ['kcal' => 0.0, 'prot' => 0.0, 'fat' => 0.0, 'carb' => 0.0];

        foreach ($menu->menuItems as $item) {
            $mt = $item->mealType;
            if (!$mt || !$item->dish) continue;
            $key = $mt->sort_order . '|' . $mt->id;
            if (!isset($byMeal[$key])) {
                $byMeal[$key] = [
                    'meal_type_id'    => $mt->id,
                    'meal_type'       => $mt->name,
                    'sort_order'      => (int) $mt->sort_order,
                    'energy_percent'  => (float) $mt->energy_percent,
                    'custom_energy'   => $item->custom_energy_percent !== null ? (float) $item->custom_energy_percent : null,
                    'dishes'          => [],
                    'actual'          => ['kcal' => 0.0, 'prot' => 0.0, 'fat' => 0.0, 'carb' => 0.0],
                ];
            }
            $t = $item->dish->calculated_totals;
            $byMeal[$key]['dishes'][] = [
                'dish_id'       => $item->dish->id,
                'name'          => $item->dish->name ?? null,
                'kcal'          => round((float)$t['kcal'], 1),
                'prot'          => round((float)$t['prot'], 1),
                'fat'           => round((float)$t['fat'], 1),
                'carb'          => round((float)$t['carb'], 1),
                'cost'          => round((float)$t['cost'], 2),
                'output_weight' => round((float)$t['output_weight'], 1),
            ];
            $byMeal[$key]['actual']['kcal'] += (float)$t['kcal'];
            $byMeal[$key]['actual']['prot'] += (float)$t['prot'];
            $byMeal[$key]['actual']['fat']  += (float)$t['fat'];
            $byMeal[$key]['actual']['carb'] += (float)$t['carb'];

            $sumActual['kcal'] += (float)$t['kcal'];
            $sumActual['prot'] += (float)$t['prot'];
            $sumActual['fat']  += (float)$t['fat'];
            $sumActual['carb'] += (float)$t['carb'];
        }

        ksort($byMeal);
        $byMeal = array_values($byMeal);
        foreach ($byMeal as &$m) {
            foreach ($m['actual'] as $k => $v) $m['actual'][$k] = round($v, 1);
        }
        unset($m);

        $payload = [
            'id'           => $menu->id,
            'menu_plan_id' => $menu->menu_plan_id,
            'menu_plan'    => $menu->menuPlan?->name,
            'day_number'   => $menu->day_number,
            'target' => [
                'kcal' => (int) $menu->target_kcal,
                'prot' => (int) $menu->target_protein_g,
                'fat'  => (int) $menu->target_fat_g,
                'carb' => (int) $menu->target_carb_g,
            ],
            'actual' => [
                'kcal' => round($sumActual['kcal'], 1),
                'prot' => round($sumActual['prot'], 1),
                'fat'  => round($sumActual['fat'], 1),
                'carb' => round($sumActual['carb'], 1),
            ],
            'meals' => $byMeal,
        ];

        if ($this->option('json')) {
            $this->line(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            return self::SUCCESS;
        }

        $this->info("DailyMenu #{$payload['id']} — день {$payload['day_number']} плану «{$payload['menu_plan']}»");
        $tg = $payload['target']; $ac = $payload['actual'];
        $this->line(sprintf('  Ціль:  ккал %d | Б %d | Ж %d | В %d', $tg['kcal'], $tg['prot'], $tg['fat'], $tg['carb']));
        $this->line(sprintf('  Факт:  ккал %.1f | Б %.1f | Ж %.1f | В %.1f', $ac['kcal'], $ac['prot'], $ac['fat'], $ac['carb']));
        $this->line(sprintf('  Дельта: %s%.1f ккал | Б %s%.1f | Ж %s%.1f | В %s%.1f',
            $ac['kcal'] >= $tg['kcal'] ? '+' : '', $ac['kcal'] - $tg['kcal'],
            $ac['prot'] >= $tg['prot'] ? '+' : '', $ac['prot'] - $tg['prot'],
            $ac['fat']  >= $tg['fat']  ? '+' : '', $ac['fat']  - $tg['fat'],
            $ac['carb'] >= $tg['carb'] ? '+' : '', $ac['carb'] - $tg['carb'],
        ));

        $this->line('');
        foreach ($byMeal as $m) {
            $energy = $m['custom_energy'] ?? $m['energy_percent'];
            $this->info(sprintf('%s [%.1f%% енергії]', $m['meal_type'], $energy));
            foreach ($m['dishes'] as $d) {
                $this->line(sprintf('  — #%d %s: %.1f ккал | Б %.1f / Ж %.1f / В %.1f | %.0f г | %.2f ₴',
                    $d['dish_id'], $d['name'], $d['kcal'], $d['prot'], $d['fat'], $d['carb'], $d['output_weight'], $d['cost']));
            }
            $a = $m['actual'];
            $this->line(sprintf('  РАЗОМ: %.1f ккал | Б %.1f | Ж %.1f | В %.1f', $a['kcal'], $a['prot'], $a['fat'], $a['carb']));
            $this->line('');
        }

        return self::SUCCESS;
    }

    /**
     * Resolves a DailyMenu either by its ID or by --plan (name/ID) + --day (cycle day).
     */
    private function resolveMenu(): DailyMenu|false
    {
        $with = [
            'menuPlan:id,name,cycle_days',
            'menuItems.mealType:id,name,sort_order,energy_percent',
            'menuItems.dish.dishIngredients.ingredient',
            'menuItems.dish.dishIngredients.childDish.dishIngredients.ingredient',
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
