<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/** Група-синонім продуктів для виключень: виключили групу — заборонені всі її продукти. */
class IngredientGroup extends Model
{
    /** Продукти без калорій, яким 0 ккал дозволено (сіль, вода, спеції) — для перевірки «битих» продуктів. */
    public const ZERO_KCAL_OK = 'zero_kcal_ok';

    protected $guarded = [];

    public function ingredients(): BelongsToMany
    {
        return $this->belongsToMany(Ingredient::class, 'ingredient_group_items');
    }

    /** @return array<string, int[]> slug → id продуктів */
    public static function map(): array
    {
        return \Illuminate\Support\Facades\DB::table('ingredient_group_items')
            ->join('ingredient_groups', 'ingredient_groups.id', '=', 'ingredient_group_items.ingredient_group_id')
            ->get(['ingredient_groups.slug', 'ingredient_group_items.ingredient_id'])
            ->groupBy('slug')
            ->map(fn ($rows) => $rows->pluck('ingredient_id')->map(fn ($id) => (int) $id)->all())
            ->all();
    }
}
