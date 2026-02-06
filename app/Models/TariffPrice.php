<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TariffPrice extends Model
{
    protected $fillable = ['tariff_id', 'calorie_range_id', 'price_per_day'];

    /**
     * До якого тарифу належить ця ціна
     */
    public function tariff(): BelongsTo
    {
        return $this->belongsTo(Tariff::class);
    }

    /**
     * До якого діапазону калорій належить ця ціна
     */
    public function calorieRange(): BelongsTo
    {
        return $this->belongsTo(CalorieRange::class);
    }
}