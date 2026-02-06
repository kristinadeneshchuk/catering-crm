<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Tariff extends Model
{
    // Дозволяємо масове заповнення всіх полів
    protected $guarded = [];

    /**
     * ПРАВИЛЬНИЙ ЗВ'ЯЗОК З ДІАПАЗОНАМИ КАЛОРІЙ
     * Це виправляє помилку "getResults() on null"
     */
    public function calorieRange(): BelongsTo
    {
        // Тариф належить до одного діапазону (напр. LIGHT 1100-1200)
        return $this->belongsTo(CalorieRange::class, 'calorie_range_id');
    }

    /**
     * ЗВ'ЯЗОК З ЦІНАМИ (МАТРИЦЯ ЦІН)
     */
    public function prices(): HasMany 
    {
        return $this->hasMany(TariffPrice::class);
    }
}