<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany; 

class CalorieRange extends Model
{
    protected $fillable = ['name', 'min_kcal', 'max_kcal'];

    protected static function booted(): void
    {
        // Коли створено новий діапазон — у кожному існуючому тарифі
        // автоматом з'являється рядок ціни (0₴), щоб не забути проставити.
        static::created(function (CalorieRange $range) {
            foreach (Tariff::all() as $tariff) {
                TariffPrice::firstOrCreate(
                    ['tariff_id' => $tariff->id, 'calorie_range_id' => $range->id],
                    ['price_per_day' => 0]
                );
            }
        });
    }

    /**
     * Зв'язок з матрицею цін
     */
    public function prices(): HasMany
    {
        return $this->hasMany(TariffPrice::class);
    }
}