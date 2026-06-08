<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    // Дозволяємо системі записувати дані у ці колонки
    protected $fillable = ['key', 'value'];

    protected static function booted(): void
    {
        // Датовані ставки: зміна оренди/комуналки фіксується в історії «діє з сьогодні»
        static::saved(function (Setting $setting) {
            if (in_array($setting->key, ['monthly_rent', 'monthly_utilities'], true)
                && ($setting->wasRecentlyCreated || $setting->wasChanged('value'))) {
                RateHistory::record($setting->key, (float) $setting->value);
            }
        });
    }
}