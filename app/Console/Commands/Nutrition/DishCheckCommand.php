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
    protected $signature = 'nutrition:dish:check {id : Dish ID або частина назви} {--json : Machine-readable output}';

    protected $description = 'Read-only: sanity check on a dish tech card';

    public function handle(): int
    {
        $dish = $this->resolveDish($this->argument('id'));
        if (!$dish instanceof Dish) {
            return self::FAILURE;
        }

        $issues = [];

        if (empty($dish->name)) {
            $issues[] = ['level' => 'warn',  'kind' => 'no_name', 'message' => 'У страви немає назви'];
        }
        if (count($dish->dishIngredients) === 0) {
            $issues[] = ['level' => 'error', 'kind' => 'no_ingredients', 'message' => 'У страви немає жодного інгредієнта — КБЖВ завжди буде 0'];
        }

        foreach ($dish->dishIngredients as $di) {
            $type   = mb_strtolower(trim((string)($di->type ?? '')));
            $netG   = (float)($di->net_weight_g ?? 0);
            $isProd = in_array($type, ['product', 'продукт'], true);
            $isPf   = in_array($type, ['pf', 'напівфабрикат', 'п/ф', 'н/ф'], true);

            if ($netG <= 0) {
                $issues[] = ['level' => 'error', 'kind' => 'zero_weight', 'message' => "Рядок інгредієнта id={$di->id}: net_weight_g = 0"];
            }

            if (!$isProd && !$isPf) {
                $issues[] = ['level' => 'error', 'kind' => 'unknown_type', 'message' => "Рядок id={$di->id}: тип «{$type}» не розпізнано (очікується product/pf)"];
                continue;
            }

            if ($isProd) {
                if (!$di->ingredient) {
                    $issues[] = ['level' => 'error', 'kind' => 'broken_ingredient_ref', 'message' => "Рядок id={$di->id}: тип product, але ingredient не знайдено"];
                    continue;
                }
                $ing = $di->ingredient;

                $hasKbzhu = ((float)$ing->proteins_100g + (float)$ing->fats_100g + (float)$ing->carbs_100g) > 0;
                if (!$hasKbzhu) {
                    $issues[] = ['level' => 'warn', 'kind' => 'ingredient_no_kbzhu', 'message' => "Інгредієнт #{$ing->id} «{$ing->name}»: всі КБЖВ-поля = 0 (немає даних)"];
                }
                $yield = (float)($ing->yield_percent ?: 0);
                if ($yield <= 0 || $yield > 200) {
                    $issues[] = ['level' => 'warn', 'kind' => 'suspicious_yield', 'message' => "Інгредієнт #{$ing->id} «{$ing->name}»: yield_percent = {$ing->yield_percent} (очікується 1..200)"];
                }
                if ((float)$ing->price_per_kg <= 0 && (float)$ing->average_price <= 0) {
                    $issues[] = ['level' => 'warn', 'kind' => 'no_price', 'message' => "Інгредієнт #{$ing->id} «{$ing->name}»: ціна не задана — собівартість не порахується"];
                }
            }

            if ($isPf) {
                if (!$di->childDish) {
                    $issues[] = ['level' => 'error', 'kind' => 'broken_pf_ref', 'message' => "Рядок id={$di->id}: тип PF, але child_dish не знайдено"];
                    continue;
                }
                $pfTotals = $di->childDish->calculated_totals;
                if ((float)$pfTotals['output_weight'] <= 0) {
                    $issues[] = ['level' => 'error', 'kind' => 'pf_zero_output', 'message' => "НФ #{$di->childDish->id} «{$di->childDish->name}»: output_weight = 0 — масштабування зламано"];
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

        $this->info("Перевірка страви #{$dish->id} «{$payload['name']}»");
        $t = $payload['totals'];
        $this->line(sprintf('  Поточне КБЖВ: ккал %.1f | Б %.1f | Ж %.1f | В %.1f | %.2f ₴', $t['kcal'], $t['prot'], $t['fat'], $t['carb'], $t['cost']));

        if (empty($issues)) {
            $this->info('  Зауважень немає.');
            return self::SUCCESS;
        }

        $this->line('');
        $this->warn("Зауважень: {$payload['issues_count']}");
        foreach ($issues as $i) {
            $tag = $i['level'] === 'error' ? '[ERROR]' : '[warn ]';
            $this->line("  {$tag} {$i['message']}");
        }

        return self::SUCCESS;
    }

    private function resolveDish(string $idOrName): Dish|false
    {
        $with = [
            'dishIngredients.ingredient',
            'dishIngredients.childDish.dishIngredients.ingredient',
        ];
        if (is_numeric($idOrName)) {
            $dish = Dish::with($with)->find((int) $idOrName);
            if (!$dish) { $this->error("Страву #{$idOrName} не знайдено."); return false; }
            return $dish;
        }
        $matches = Dish::with($with)->where('name', 'like', '%' . $idOrName . '%')->get();
        if ($matches->isEmpty()) { $this->error("Страву «{$idOrName}» не знайдено."); return false; }
        if ($matches->count() === 1) return $matches->first();
        $this->warn("Знайдено кілька страв за запитом «{$idOrName}»:");
        foreach ($matches as $d) $this->line("  #{$d->id} — {$d->name}");
        $this->line('Уточни запит або вкажи ID.');
        return false;
    }
}
