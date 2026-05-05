<?php

namespace App\Console\Commands\Nutrition;

use App\Models\Client;
use Illuminate\Console\Command;

/**
 * READ-ONLY. Client profile snapshot for nutrition advisor.
 */
class ClientCommand extends Command
{
    protected $signature = 'nutrition:client {id : Client ID} {--json : Machine-readable output}';

    protected $description = 'Read-only: client profile for nutrition planning';

    public function handle(): int
    {
        $client = Client::with([
            'ingredientExclusions:id,name',
            'dishExclusions:id,name',
            'replacementBundles.items.originalIngredient:id,name',
            'replacementBundles.items.replacementIngredient:id,name',
        ])->find($this->argument('id'));

        if (!$client) {
            $this->error("Клиент #{$this->argument('id')} не найден.");
            return self::FAILURE;
        }

        $mealTypes = $client->mealTypes()
            ->withPivot('energy_percent')
            ->orderBy('sort_order')
            ->get()
            ->map(fn($mt) => [
                'id'             => $mt->id,
                'name'           => $mt->name,
                'sort_order'     => (int) $mt->sort_order,
                'energy_percent' => (float) ($mt->pivot->energy_percent ?? $mt->energy_percent),
                'is_overridden'  => $mt->pivot->energy_percent !== null,
            ])
            ->values()
            ->all();

        $excludedIngrIds = $client->effectiveExcludedIngredientIds();

        $activeOrder = $client->orders()
            ->whereNotIn('status', ['finished', 'completed'])
            ->orderBy('start_date')
            ->with('menuPlan:id,name,cycle_days')
            ->first();

        $payload = [
            'id'             => $client->id,
            'name'           => $client->name,
            'phone'          => $client->phone,
            'target_kcal'    => $client->target_kcal,
            'has_cutlery'    => (bool) $client->has_cutlery,
            'meal_types'     => $mealTypes,
            'allergen_ingredients' => $this->collectAllergenIngredientIds($client),
            'exclusions' => [
                'manual_ingredients'       => $client->ingredientExclusions->map(fn($i) => ['id' => $i->id, 'name' => $i->name])->all(),
                'manual_dishes'            => $client->dishExclusions->map(fn($d) => ['id' => $d->id, 'name' => $d->name])->all(),
                'effective_ingredient_ids' => $excludedIngrIds,
            ],
            'replacement_bundles' => $client->replacementBundles->map(fn($b) => [
                'id'    => $b->id,
                'name'  => $b->name,
                'items' => $b->items->map(fn($i) => [
                    'original'    => $i->originalIngredient ? ['id' => $i->originalIngredient->id, 'name' => $i->originalIngredient->name] : null,
                    'replacement' => $i->replacementIngredient ? ['id' => $i->replacementIngredient->id, 'name' => $i->replacementIngredient->name] : null,
                ])->all(),
            ])->all(),
            'active_order' => $activeOrder ? [
                'id'           => $activeOrder->id,
                'status'       => $activeOrder->status,
                'calories'     => $activeOrder->calories,
                'menu_plan_id' => $activeOrder->menu_plan_id,
                'menu_plan'    => $activeOrder->menuPlan?->name,
                'start_date'   => optional($activeOrder->start_date)->toDateString(),
                'end_date'     => optional($activeOrder->end_date)->toDateString(),
            ] : null,
        ];

        if ($this->option('json')) {
            $this->line(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            return self::SUCCESS;
        }

        $this->info("Клиент #{$payload['id']} — {$payload['name']}");
        $this->line('  Целевая калорийность: ' . ($payload['target_kcal'] ?? '—') . ' ккал');
        $this->line('  Телефон: ' . ($payload['phone'] ?: '—'));

        $this->line('');
        $this->info('Приёмы пищи:');
        if (empty($mealTypes)) {
            $this->warn('  (не настроены — это сломает анализ меню)');
        } else {
            foreach ($mealTypes as $m) {
                $tag = $m['is_overridden'] ? ' [override]' : '';
                $this->line(sprintf('  — %s: %.1f%%%s', $m['name'], $m['energy_percent'], $tag));
            }
        }

        $this->line('');
        $this->info('Аллергены (через эффективные исключения):');
        if (empty($payload['allergen_ingredients'])) {
            $this->line('  (не задано)');
        } else {
            foreach ($payload['allergen_ingredients'] as $a) {
                $this->line("  — {$a['allergen']} → " . implode(', ', $a['ingredient_names']));
            }
        }

        $this->line('');
        $this->info('Исключения:');
        $manualIngr = $payload['exclusions']['manual_ingredients'];
        $manualDish = $payload['exclusions']['manual_dishes'];
        $effectiveCount = count($payload['exclusions']['effective_ingredient_ids']);
        $this->line('  Ингредиенты вручную: ' . (count($manualIngr) ? implode(', ', array_column($manualIngr, 'name')) : '—'));
        $this->line('  Блюда вручную: ' . (count($manualDish) ? implode(', ', array_column($manualDish, 'name')) : '—'));
        $this->line("  Эффективно исключено ингредиентов (с бандлами): {$effectiveCount}");

        $this->line('');
        $this->info('Бандлы замен:');
        if (empty($payload['replacement_bundles'])) {
            $this->line('  (не привязаны)');
        } else {
            foreach ($payload['replacement_bundles'] as $b) {
                $this->line("  — {$b['name']} (#{$b['id']}): " . count($b['items']) . ' замен');
            }
        }

        $this->line('');
        $this->info('Активный заказ:');
        if (!$payload['active_order']) {
            $this->line('  (нет)');
        } else {
            $o = $payload['active_order'];
            $this->line("  #{$o['id']} | {$o['status']} | {$o['calories']} ккал | план: " . ($o['menu_plan'] ?: '—') . " (id {$o['menu_plan_id']})");
            $this->line("  период: {$o['start_date']} → {$o['end_date']}");
        }

        return self::SUCCESS;
    }

    private function collectAllergenIngredientIds(Client $client): array
    {
        $excludedIds = $client->effectiveExcludedIngredientIds();
        if (empty($excludedIds)) return [];

        $rows = \App\Models\Ingredient::with('allergens:id,name')
            ->whereIn('id', $excludedIds)
            ->get();

        $byAllergen = [];
        foreach ($rows as $ing) {
            foreach ($ing->allergens as $a) {
                $byAllergen[$a->name]['allergen'] = $a->name;
                $byAllergen[$a->name]['ingredient_names'][] = $ing->name;
            }
        }
        return array_values($byAllergen);
    }
}
