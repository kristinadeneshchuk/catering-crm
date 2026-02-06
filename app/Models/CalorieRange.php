<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany; 

class CalorieRange extends Model
{
    protected $fillable = ['name', 'min_kcal', 'max_kcal'];

    /**
     * Зв'язок з матрицею цін
     */
    public function prices(): HasMany
    {
        return $this->hasMany(TariffPrice::class);
    }
}