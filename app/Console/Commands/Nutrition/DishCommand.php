<?php

namespace App\Console\Commands\Nutrition;

use App\Models\Dish;
use Illuminate\Console\Command;

/**
 * READ-ONLY. Tech-card snapshot for a dish.
 */
class DishCommand extends Command
{
    protected $signature = 'nutrition:dish {id : Dish ID} {--json : Machine-readable output}';

    protected $description = 'Read-only: dish tech card with ingredients, КБЖУ, allergens, cost';

    public function handle(): int
    {
        $dish = Dish::with([
            'dishIngredients.ingredient.allergens:id,name',
            'dishIngredients.childDish.dishIngredients.ingredient',
        ])->find($this->argument('id'));

        if (!$dish) {
            $this->error("Блюдо #{$this->argument('id')} не найдено.");
            return self::FAILURE;
        }

        $totals = $dish->calculated_totals;

        $rows = [];
        $allergens = [];
        foreach ($dish->dishIngredients as $di) {
            $type = mb_strtolower(trim((string) ($di->type ?? '')));
            $isProduct = in_array($type, ['product', 'продукт'], true);
            $isPf      = in_array($type, ['pf', 'напівфабрикат', 'п/ф', 'н/ф'], true);
            $netG      = (float) ($di->net_weight_g ?? 0);

            if ($isProduct && $di->ingredient) {
                $ing = $di->ingredient;
                $rows[] = [
                    'kind'    => 'product',
                    'id'      => $ing->id,
                    'name'    => $ing->name,
                    'unit'    => $ing->unit,
                    'net_g'   => round($netG, 1),
                    'kcal'    => round($this->kcalFromMacros((float)$ing->proteins_100g, (float)$ing->fats_100g, (float)$ing->carbs_100g) * $netG / 100, 1),
                    'prot'    => round((float)$ing->proteins_100g * $netG / 100, 1),
                    'fat'     => round((float)$ing->fats_100g     * $netG / 100, 1),
                    'carb'    => round((float)$ing->carbs_100g    * $netG / 100, 1),
                    'has_kbzhu_data' => $this->ingredientHasKbzhu($ing),
                    'allergens' => $ing->allergens->pluck('name')->all(),
                ];
                foreach ($ing->allergens as $a) $allergens[$a->name] = true;
            } elseif ($isPf && $di->childDish) {
                $pfTotals = $di->childDish->calculated_totals;
                $pfOutput = (float)($pfTotals['output_weight'] ?? 0);
                $ratio    = $pfOutput > 0 ? ($netG / $pfOutput) : 0;

                $rows[] = [
                    'kind'  => 'pf',
                    'id'    => $di->childDish->id,
                    'name'  => '[ПФ] ' . ($di->childDish->name ?? ('Dish #' . $di->childDish->id)),
                    'unit'  => 'г',
                    'net_g' => round($netG, 1),
                    'kcal'  => round((float)$pfTotals['kcal'] * $ratio, 1),
                    'prot'  => round((float)$pfTotals['prot'] * $ratio, 1),
                    'fat'   => round((float)$pfTotals['fat']  * $ratio, 1),
                    'carb'  => round((float)$pfTotals['carb'] * $ratio, 1),
                    'has_kbzhu_data' => true,
                    'allergens' => [],
                ];
            } else {
                $rows[] = [
                    'kind'   => $type ?: 'unknown',
                    'id'     => null,
                    'name'   => '(битая строка техкарты — нет ingredient/child_dish)',
                    'unit'   => null,
                    'net_g'  => round($netG, 1),
                    'kcal'   => 0, 'prot' => 0, 'fat' => 0, 'carb' => 0,
                    'has_kbzhu_data' => false,
                    'allergens' => [],
                ];
            }
        }

        $payload = [
            'id'             => $dish->id,
            'name'           => $dish->name ?? null,
            'group'          => $dish->group ?? null,
            'base_weight_g'  => $dish->base_weight_g ?? null,
            'totals'         => [
                'kcal'          => round((float)$totals['kcal'], 1),
                'prot'          => round((float)$totals['prot'], 1),
                'fat'           => round((float)$totals['fat'], 1),
                'carb'          => round((float)$totals['carb'], 1),
                'cost'          => round((float)$totals['cost'], 2),
                'input_weight'  => round((float)$totals['input_weight'], 1),
                'output_weight' => round((float)$totals['output_weight'], 1),
            ],
            'ingredients' => $rows,
            'allergens'   => array_keys($allergens),
        ];

        if ($this->option('json')) {
            $this->line(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            return self::SUCCESS;
        }

        $this->info("Блюдо #{$payload['id']} — " . ($payload['name'] ?: '(без названия)'));
        if ($payload['group'])         $this->line('  Группа: ' . $payload['group']);
        if ($payload['base_weight_g']) $this->line('  Заданный выход: ' . $payload['base_weight_g'] . ' г');

        $this->line('');
        $t = $payload['totals'];
        $this->info('Итого:');
        $this->line(sprintf('  ККал: %.1f | Б: %.1f | Ж: %.1f | У: %.1f | Цена: %.2f ₴', $t['kcal'], $t['prot'], $t['fat'], $t['carb'], $t['cost']));
        $this->line(sprintf('  Закладка: %.1f г | Выход: %.1f г', $t['input_weight'], $t['output_weight']));

        $this->line('');
        $this->info('Состав:');
        $this->table(
            ['Тип', 'ID', 'Название', 'Нетто, г', 'ккал', 'Б', 'Ж', 'У', 'КБЖУ?', 'Аллергены'],
            array_map(fn($r) => [
                $r['kind'], $r['id'] ?? '—', $r['name'], $r['net_g'],
                $r['kcal'], $r['prot'], $r['fat'], $r['carb'],
                $r['has_kbzhu_data'] ? 'да' : 'НЕТ',
                implode(',', $r['allergens']) ?: '—',
            ], $rows)
        );

        if (!empty($payload['allergens'])) {
            $this->line('');
            $this->info('Аллергены блюда: ' . implode(', ', $payload['allergens']));
        }

        return self::SUCCESS;
    }

    private function kcalFromMacros(float $p, float $f, float $c): float
    {
        return $p * 4 + $f * 9 + $c * 4;
    }

    private function ingredientHasKbzhu($ing): bool
    {
        return ((float)$ing->proteins_100g + (float)$ing->fats_100g + (float)$ing->carbs_100g) > 0;
    }
}
