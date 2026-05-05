<?php

namespace App\Console\Commands\Nutrition;

use App\Models\Dish;
use Illuminate\Console\Command;

/**
 * READ-ONLY. Side-by-side compare of two dishes.
 */
class DishCompareCommand extends Command
{
    protected $signature = 'nutrition:dish:compare {id1 : Перша страва (ID або назва)} {id2 : Друга страва (ID або назва)} {--json : Machine-readable output}';

    protected $description = 'Read-only: compare two dishes by KBZHU, cost, allergens';

    public function handle(): int
    {
        $a = $this->resolveDish($this->argument('id1'));
        if (!$a) return self::FAILURE;
        $b = $this->resolveDish($this->argument('id2'));
        if (!$b) return self::FAILURE;

        $payload = ['a' => $a, 'b' => $b, 'delta' => [
            'kcal' => round($b['kcal'] - $a['kcal'], 1),
            'prot' => round($b['prot'] - $a['prot'], 1),
            'fat'  => round($b['fat']  - $a['fat'],  1),
            'carb' => round($b['carb'] - $a['carb'], 1),
            'cost' => round($b['cost'] - $a['cost'], 2),
            'allergens_only_in_a' => array_values(array_diff($a['allergens'], $b['allergens'])),
            'allergens_only_in_b' => array_values(array_diff($b['allergens'], $a['allergens'])),
        ]];

        if ($this->option('json')) {
            $this->line(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            return self::SUCCESS;
        }

        $this->info("Порівняння: #{$a['id']} «{$a['name']}» vs #{$b['id']} «{$b['name']}»");
        $this->table(
            ['', 'A: ' . $a['name'], 'B: ' . $b['name'], 'Δ (B-A)'],
            [
                ['ккал',      $a['kcal'], $b['kcal'], $payload['delta']['kcal']],
                ['Білок',     $a['prot'], $b['prot'], $payload['delta']['prot']],
                ['Жир',       $a['fat'],  $b['fat'],  $payload['delta']['fat']],
                ['Вуглеводи', $a['carb'], $b['carb'], $payload['delta']['carb']],
                ['Ціна, ₴',   $a['cost'], $b['cost'], $payload['delta']['cost']],
                ['Вихід, г',  $a['output_weight'], $b['output_weight'], round($b['output_weight'] - $a['output_weight'], 1)],
                ['Алергени',  implode(',', $a['allergens']) ?: '—', implode(',', $b['allergens']) ?: '—', '—'],
            ]
        );

        if (!empty($payload['delta']['allergens_only_in_a'])) {
            $this->line('  Тільки в A: ' . implode(', ', $payload['delta']['allergens_only_in_a']));
        }
        if (!empty($payload['delta']['allergens_only_in_b'])) {
            $this->line('  Тільки в B: ' . implode(', ', $payload['delta']['allergens_only_in_b']));
        }

        return self::SUCCESS;
    }

    private function resolveDish(string $idOrName): ?array
    {
        $with = [
            'dishIngredients.ingredient.allergens:id,name',
            'dishIngredients.childDish.dishIngredients.ingredient.allergens:id,name',
        ];
        if (is_numeric($idOrName)) {
            $dish = Dish::with($with)->find((int) $idOrName);
            if (!$dish) { $this->error("Страву #{$idOrName} не знайдено."); return null; }
        } else {
            $matches = Dish::with($with)->where('name', 'like', '%' . $idOrName . '%')->get();
            if ($matches->isEmpty()) { $this->error("Страву «{$idOrName}» не знайдено."); return null; }
            if ($matches->count() > 1) {
                $this->warn("Знайдено кілька страв за запитом «{$idOrName}»:");
                foreach ($matches as $d) $this->line("  #{$d->id} — {$d->name}");
                $this->line('Уточни запит або вкажи ID.');
                return null;
            }
            $dish = $matches->first();
        }

        $t = $dish->calculated_totals;
        $allergens = [];
        foreach ($dish->dishIngredients as $di) {
            if ($di->ingredient) foreach ($di->ingredient->allergens as $a) $allergens[$a->name] = true;
            if ($di->childDish) {
                foreach ($di->childDish->dishIngredients as $sub) {
                    if ($sub->ingredient) foreach ($sub->ingredient->allergens as $a) $allergens[$a->name] = true;
                }
            }
        }

        return [
            'id'    => $dish->id,
            'name'  => $dish->name ?? '(без назви)',
            'group' => $dish->group ?? null,
            'kcal'  => round((float)$t['kcal'], 1),
            'prot'  => round((float)$t['prot'], 1),
            'fat'   => round((float)$t['fat'],  1),
            'carb'  => round((float)$t['carb'], 1),
            'cost'  => round((float)$t['cost'], 2),
            'output_weight' => round((float)$t['output_weight'], 1),
            'allergens' => array_keys($allergens),
        ];
    }
}
