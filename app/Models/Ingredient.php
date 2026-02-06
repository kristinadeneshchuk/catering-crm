<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Ingredient extends Model
{
    // Список доступних одиниць виміру
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
        'stock' => 'decimal:3', // Гарантує, що 0.999 не округлиться
        'price_per_kg' => 'decimal:2',
        'yield_percent' => 'integer',
        'calories_100g' => 'integer',
        'proteins_100g' => 'float',
        'fats_100g' => 'float',
        'carbs_100g' => 'float',
    ];

    /**
     * Зв'язок зі стравами через таблицю-посередник.
     */
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