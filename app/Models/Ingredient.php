<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Ingredient extends Model
{
    public const UNITS = [
        'g' => 'г',
        'kg' => 'кг',
        'ml' => 'мл',
        'l' => 'л',
        'pcs' => 'шт',
    ];

    protected $fillable = [
        'name', 'unit', 'price_per_kg', 'yield_percent',
        'calories_100g', 'proteins_100g', 'fats_100g', 'carbs_100g',
        'stock', 'group', 'photo'
    ];

    protected $casts = [
        'stock' => 'decimal:3',
        'price_per_kg' => 'decimal:2',
        'yield_percent' => 'integer',
        'calories_100g' => 'integer',
        'proteins_100g' => 'float',
        'fats_100g' => 'float',
        'carbs_100g' => 'float',
    ];

    public function getAveragePriceAttribute(): float
    {
        $avgData = \App\Models\StockDocumentItem::query()
            ->where('itemable_id', $this->id)
            ->where('itemable_type', self::class)
            ->whereHas('stockDocument', fn($q) => $q->where('type', 'receipt'))
            ->selectRaw('SUM(qty * price) as total_cost, SUM(qty) as total_qty')
            ->first();

        if ($avgData && $avgData->total_qty > 0) {
            return (float) ($avgData->total_cost / $avgData->total_qty);
        }

        return (float) $this->price_per_kg;
    }

    public function getTotalSpentAttribute(): float
    {
        return (float) \App\Models\StockDocumentItem::query()
            ->whereIn('itemable_type', [self::class, 'App\Models\Ingredient'])
            ->where('itemable_id', $this->id)
            ->whereHas('stockDocument', fn($q) => $q->where('type', 'receipt'))
            ->sum(\Illuminate\Support\Facades\DB::raw('qty * price'));
    }

    public function allergens(): BelongsToMany
    {
        return $this->belongsToMany(Allergen::class, 'allergen_ingredient');
    }

    public function dishes(): BelongsToMany
    {
        return $this->belongsToMany(
            Dish::class,
            'dish_ingredients',
            'ingredient_id',
            'dish_id'
        )->withPivot('net_weight_g');
    }
}
