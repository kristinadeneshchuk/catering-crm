<?php

namespace App\Console\Commands\Nutrition;

use App\Models\Dish;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * READ-ONLY. Search dish catalog by KBZHU/allergen/ingredient parameters.
 * Used by the skill to suggest substitutions.
 */
class DishFindCommand extends Command
{
    protected $signature = 'nutrition:dish:find
        {--kcal= : Target kcal per portion}
        {--kcal-tol=10 : Tolerance for kcal (percent)}
        {--protein-min= : Minimum protein, g}
        {--protein-max= : Maximum protein, g}
        {--fat-max= : Maximum fat, g}
        {--carb-max= : Maximum carbs, g}
        {--meal= : Meal type id or name (filters dishes that appear in DailyMenuDish under this meal_type)}
        {--exclude-ingr= : Comma-separated ingredient IDs to exclude}
        {--no-allergens= : Comma-separated allergen names to exclude}
        {--name-like= : Filter by dish name substring}
        {--limit=20 : Max results}
        {--json : Machine-readable output}';

    protected $description = 'Read-only: search dishes matching KBZHU/allergen/ingredient filters';

    public function handle(): int
    {
        $kcal       = $this->option('kcal') !== null ? (float) $this->option('kcal') : null;
        $kcalTol    = (float) $this->option('kcal-tol');
        $protMin    = $this->option('protein-min') !== null ? (float) $this->option('protein-min') : null;
        $protMax    = $this->option('protein-max') !== null ? (float) $this->option('protein-max') : null;
        $fatMax     = $this->option('fat-max')     !== null ? (float) $this->option('fat-max')     : null;
        $carbMax    = $this->option('carb-max')    !== null ? (float) $this->option('carb-max')    : null;
        $excludeIng = $this->parseCsvInts($this->option('exclude-ingr'));
        $noAllergens= $this->parseCsv($this->option('no-allergens'));
        $nameLike   = $this->option('name-like');
        $limit      = (int) $this->option('limit');

        $query = Dish::with([
            'dishIngredients.ingredient.allergens:id,name',
            'dishIngredients.childDish.dishIngredients.ingredient.allergens:id,name',
        ]);

        if ($nameLike) {
            $query->where('name', 'like', '%' . $nameLike . '%');
        }

        if ($mealOpt = $this->option('meal')) {
            $mealId = is_numeric($mealOpt)
                ? (int) $mealOpt
                : (\App\Models\MealType::where('name', $mealOpt)->value('id') ?? 0);
            if ($mealId > 0) {
                $query->whereExists(function ($q) use ($mealId) {
                    $q->select(DB::raw(1))
                      ->from('daily_menu_dishes')
                      ->whereColumn('daily_menu_dishes.dish_id', 'dishes.id')
                      ->where('daily_menu_dishes.meal_type_id', $mealId);
                });
            }
        }

        $candidates = $query->limit(max($limit * 5, 100))->get();

        $kcalLow  = $kcal !== null ? $kcal * (1 - $kcalTol / 100) : null;
        $kcalHigh = $kcal !== null ? $kcal * (1 + $kcalTol / 100) : null;

        $matches = [];
        foreach ($candidates as $dish) {
            $totals = $dish->calculated_totals;
            $k = (float) $totals['kcal'];
            $p = (float) $totals['prot'];
            $f = (float) $totals['fat'];
            $c = (float) $totals['carb'];

            if ($kcalLow  !== null && $k < $kcalLow)  continue;
            if ($kcalHigh !== null && $k > $kcalHigh) continue;
            if ($protMin  !== null && $p < $protMin)  continue;
            if ($protMax  !== null && $p > $protMax)  continue;
            if ($fatMax   !== null && $f > $fatMax)   continue;
            if ($carbMax  !== null && $c > $carbMax)  continue;

            $usedIngrIds = [];
            $allergens   = [];
            foreach ($dish->dishIngredients as $di) {
                if ($di->ingredient) {
                    $usedIngrIds[$di->ingredient->id] = true;
                    foreach ($di->ingredient->allergens as $a) $allergens[$a->name] = true;
                }
                if ($di->childDish) {
                    foreach ($di->childDish->dishIngredients as $sub) {
                        if ($sub->ingredient) {
                            $usedIngrIds[$sub->ingredient->id] = true;
                            foreach ($sub->ingredient->allergens as $a) $allergens[$a->name] = true;
                        }
                    }
                }
            }

            if (!empty($excludeIng) && array_intersect($excludeIng, array_keys($usedIngrIds))) continue;

            if (!empty($noAllergens)) {
                $hits = array_intersect(
                    array_map('mb_strtolower', $noAllergens),
                    array_map('mb_strtolower', array_keys($allergens))
                );
                if (!empty($hits)) continue;
            }

            $matches[] = [
                'id'    => $dish->id,
                'name'  => $dish->name ?? null,
                'group' => $dish->group ?? null,
                'kcal'  => round($k, 1),
                'prot'  => round($p, 1),
                'fat'   => round($f, 1),
                'carb'  => round($c, 1),
                'cost'  => round((float) $totals['cost'], 2),
                'output_weight' => round((float) $totals['output_weight'], 1),
                'allergens'     => array_keys($allergens),
            ];

            if (count($matches) >= $limit) break;
        }

        if ($kcal !== null) {
            usort($matches, fn($a, $b) => abs($a['kcal'] - $kcal) <=> abs($b['kcal'] - $kcal));
        } else {
            usort($matches, fn($a, $b) => $a['kcal'] <=> $b['kcal']);
        }

        $payload = [
            'criteria' => [
                'kcal' => $kcal, 'kcal_tol_percent' => $kcalTol,
                'protein_min' => $protMin, 'protein_max' => $protMax,
                'fat_max' => $fatMax, 'carb_max' => $carbMax,
                'meal' => $this->option('meal'),
                'exclude_ingr' => $excludeIng,
                'no_allergens' => $noAllergens,
                'name_like' => $nameLike,
                'limit' => $limit,
            ],
            'count'   => count($matches),
            'matches' => $matches,
        ];

        if ($this->option('json')) {
            $this->line(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            return self::SUCCESS;
        }

        $this->info("Знайдено: {$payload['count']}");
        if (empty($matches)) {
            $this->line('  За заданими критеріями нічого не підійшло — послаб фільтри.');
            return self::SUCCESS;
        }

        $this->table(
            ['ID', 'Назва', 'ккал', 'Б', 'Ж', 'В', 'г', '₴', 'Алергени'],
            array_map(fn($m) => [
                $m['id'], $m['name'], $m['kcal'], $m['prot'], $m['fat'], $m['carb'],
                $m['output_weight'], $m['cost'],
                implode(',', $m['allergens']) ?: '—',
            ], $matches)
        );

        return self::SUCCESS;
    }

    private function parseCsv(?string $s): array
    {
        if (!$s) return [];
        return array_values(array_filter(array_map('trim', explode(',', $s))));
    }

    private function parseCsvInts(?string $s): array
    {
        return array_map('intval', $this->parseCsv($s));
    }
}
