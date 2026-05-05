<?php

namespace App\Console\Commands\Nutrition;

use App\Models\Dish;
use Illuminate\Console\Command;

/**
 * READ-ONLY. Sanity-check a dish tech card: ingredients without KBZHU,
 * suspicious yield_percent, missing prices, broken PF references.
 */
class DishCheckCommand extends Command
{
    protected $signature = 'nutrition:dish:check {id : Dish ID} {--json : Machine-readable output}';

    protected $description = 'Read-only: sanity check on a dish tech card';

    public function handle(): int
    {
        $dish = Dish::with([
            'dishIngredients.ingredient',
            'dishIngredients.childDish.dishIngredients.ingredient',
        ])->find($this->argument('id'));

        if (!$dish) {
            $this->error("Блюдо #{$this->argument('id')} не найдено.");
            return self::FAILURE;
        }

        $issues = [];

        if (empty($dish->name)) {
            $issues[] = ['level' => 'warn',  'kind' => 'no_name', 'message' => 'У блюда нет названия'];
        }
        if (count($dish->dishIngredients) === 0) {
            $issues[] = ['level' => 'error', 'kind' => 'no_ingredients', 'message' => 'У блюда нет ни одного ингредиента — КБЖУ всегда будет 0'];
        }

        foreach ($dish->dishIngredients as $di) {
            $type   = mb_strtolower(trim((string)($di->type ?? '')));
            $netG   = (float)($di->net_weight_g ?? 0);
            $isProd = in_array($type, ['product', 'продукт'], true);
            $isPf   = in_array($type, ['pf', 'напівфабрикат', 'п/ф', 'н/ф'], true);

            if ($netG <= 0) {
                $issues[] = ['level' => 'error', 'kind' => 'zero_weight', 'message' => "Строка ингредиента id={$di->id}: net_weight_g = 0"];
            }

            if (!$isProd && !$isPf) {
                $issues[] = ['level' => 'error', 'kind' => 'unknown_type', 'message' => "Строка id={$di->id}: тип «{$type}» не распознан (ожидается product/pf)"];
                continue;
            }

            if ($isProd) {
                if (!$di->ingredient) {
                    $issues[] = ['level' => 'error', 'kind' => 'broken_ingredient_ref', 'message' => "Строка id={$di->id}: тип product, но ingredient не найден"];
                    continue;
                }
                $ing = $di->ingredient;

                $hasKbzhu = ((float)$ing->proteins_100g + (float)$ing->fats_100g + (float)$ing->carbs_100g) > 0;
                if (!$hasKbzhu) {
                    $issues[] = ['level' => 'warn', 'kind' => 'ingredient_no_kbzhu', 'message' => "Ингредиент #{$ing->id} «{$ing->name}»: все КБЖУ-поля = 0 (нет данных)"];
                }
                $yield = (float)($ing->yield_percent ?: 0);
                if ($yield <= 0 || $yield > 200) {
                    $issues[] = ['level' => 'warn', 'kind' => 'suspicious_yield', 'message' => "Ингредиент #{$ing->id} «{$ing->name}»: yield_percent = {$ing->yield_percent} (ожидается 1..200)"];
                }
                if ((float)$ing->price_per_kg <= 0 && (float)$ing->average_price <= 0) {
                    $issues[] = ['level' => 'warn', 'kind' => 'no_price', 'message' => "Ингредиент #{$ing->id} «{$ing->name}»: цена не задана — себестоимость не посчитается"];
                }
            }

            if ($isPf) {
                if (!$di->childDish) {
                    $issues[] = ['level' => 'error', 'kind' => 'broken_pf_ref', 'message' => "Строка id={$di->id}: тип PF, но child_dish не найден"];
                    continue;
                }
                $pfTotals = $di->childDish->calculated_totals;
                if ((float)$pfTotals['output_weight'] <= 0) {
                    $issues[] = ['level' => 'error', 'kind' => 'pf_zero_output', 'message' => "ПФ #{$di->childDish->id} «{$di->childDish->name}»: output_weight = 0 — масштабирование сломано"];
                }
            }
        }

        $totals = $dish->calculated_totals;

        $payload = [
            'dish_id' => $dish->id,
            'name'    => $dish->name ?? null,
            'totals'  => [
                'kcal' => round((float)$totals['kcal'], 1),
                'prot' => round((float)$totals['prot'], 1),
                'fat'  => round((float)$totals['fat'],  1),
                'carb' => round((float)$totals['carb'], 1),
                'cost' => round((float)$totals['cost'], 2),
                'output_weight' => round((float)$totals['output_weight'], 1),
            ],
            'issues_count' => count($issues),
            'issues'       => $issues,
        ];

        if ($this->option('json')) {
            $this->line(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            return self::SUCCESS;
        }

        $this->info("Проверка блюда #{$dish->id} «{$payload['name']}»");
        $t = $payload['totals'];
        $this->line(sprintf('  Текущее КБЖУ: ккал %.1f | Б %.1f | Ж %.1f | У %.1f | %.2f ₴', $t['kcal'], $t['prot'], $t['fat'], $t['carb'], $t['cost']));

        if (empty($issues)) {
            $this->info('  Замечаний нет.');
            return self::SUCCESS;
        }

        $this->line('');
        $this->warn("Замечаний: {$payload['issues_count']}");
        foreach ($issues as $i) {
            $tag = $i['level'] === 'error' ? '[ERROR]' : '[warn ]';
            $this->line("  {$tag} {$i['message']}");
        }

        return self::SUCCESS;
    }
}
