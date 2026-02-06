<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DishIngredient extends Model
{
    protected $table = 'dish_ingredients';
    public $timestamps = false;

    protected $fillable = [
        'dish_id', 
        'ingredient_id', 
        'child_dish_id', 
        'net_weight_g',
        'type'
    ];

    public function dish(): BelongsTo
    {
        return $this->belongsTo(Dish::class);
    }

    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class);
    }

    public function childDish(): BelongsTo 
    {
        return $this->belongsTo(Dish::class, 'child_dish_id');
    }
}