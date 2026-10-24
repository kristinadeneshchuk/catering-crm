<?php

namespace App\Console\Commands;

use App\Models\Ingredient;
use App\Models\IngredientGroup;
use App\Services\Agents\IngredientGroupCatalog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Наповнює групи-синоніми продуктів за назвами довідника (IngredientGroupCatalog).
 * Без --write лише показує, що куди потрапить.
 */
class AgentsIngredientGroups extends Command
{
    protected $signature = 'agents:ingredient-groups {--write : записати в базу} {--group= : показати лише одну групу}';

    protected $description = 'Групи-синоніми продуктів для перевірки виключень (dry-run без --write)';

    public function handle(): int
    {
        $ingredients = Ingredient::orderBy('name')->get(['id', 'name']);
        $names = $ingredients->pluck('name', 'id');
        $matched = IngredientGroupCatalog::match($ingredients);
        $catalog = IngredientGroupCatalog::all();

        foreach ($matched as $slug => $ids) {
            if ($this->option('group') && $this->option('group') !== $slug) continue;
            $this->line(sprintf('<info>%s</info> (%s) — %d: %s', $slug, $catalog[$slug]['name'], count($ids), collect($ids)->map(fn ($id) => $names[$id])->implode(' | ')));
        }

        if (!$this->option('write')) {
            $this->warn('Dry-run. Записати: --write');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($matched, $catalog) {
            foreach ($matched as $slug => $ids) {
                $group = IngredientGroup::updateOrCreate(['slug' => $slug], ['name' => $catalog[$slug]['name']]);
                $group->ingredients()->sync($ids);
            }
        });
        $this->info('Записано груп: ' . count($matched));

        return self::SUCCESS;
    }
}
