<?php

namespace App\Console\Commands\Nutrition;

use App\Models\Tariff;
use Illuminate\Console\Command;

/**
 * READ-ONLY. Tariff packages with the price matrix per calorie range.
 * Used by the menu-builder skill to pick a package and derive the daily cost corridor.
 */
class TariffsCommand extends Command
{
    protected $signature = 'nutrition:tariffs
        {--project= : Filter by project slug}
        {--all : Include inactive tariffs}
        {--json : Machine-readable output}';

    protected $description = 'Read-only: tariff packages with price-per-day matrix by calorie range';

    public function handle(): int
    {
        $query = Tariff::with(['prices.calorieRange', 'defaultMenuPlan:id,name,cycle_days']);

        if (!$this->option('all')) {
            $query->where('is_active', true);
        }
        if ($project = $this->option('project')) {
            $query->where('project', $project);
        }

        $tariffs = $query->orderBy('name')->get();

        $payload = $tariffs->map(fn(Tariff $t) => [
            'id'        => $t->id,
            'name'      => $t->name,
            'project'   => $t->project,
            'is_active' => (bool) $t->is_active,
            'default_menu_plan' => $t->defaultMenuPlan
                ? ['id' => $t->defaultMenuPlan->id, 'name' => $t->defaultMenuPlan->name, 'cycle_days' => $t->defaultMenuPlan->cycle_days]
                : null,
            'prices' => $t->prices
                ->sortBy(fn($p) => $p->calorieRange?->min_kcal ?? 0)
                ->values()
                ->map(fn($p) => [
                    'calorie_range_id' => $p->calorie_range_id,
                    'range'            => $p->calorieRange?->name,
                    'min_kcal'         => $p->calorieRange?->min_kcal,
                    'max_kcal'         => $p->calorieRange?->max_kcal,
                    'price_per_day'    => (float) $p->price_per_day,
                ])->all(),
        ])->values()->all();

        if ($this->option('json')) {
            $this->line(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            return self::SUCCESS;
        }

        if (empty($payload)) {
            $this->warn('Тарифів не знайдено.');
            return self::SUCCESS;
        }

        foreach ($payload as $t) {
            $status = $t['is_active'] ? '' : ' [неактивний]';
            $proj   = $t['project'] ? " | project: {$t['project']}" : '';
            $this->info("#{$t['id']} {$t['name']}{$proj}{$status}");
            if ($t['default_menu_plan']) {
                $this->line("  План за замовчуванням: {$t['default_menu_plan']['name']} ({$t['default_menu_plan']['cycle_days']} дн.)");
            }
            foreach ($t['prices'] as $p) {
                $range = $p['range'] ?? '?';
                $this->line(sprintf('  — %s (%s–%s ккал): %.0f ₴/день', $range, $p['min_kcal'] ?? '?', $p['max_kcal'] ?? '?', $p['price_per_day']));
            }
            $this->line('');
        }

        return self::SUCCESS;
    }
}
