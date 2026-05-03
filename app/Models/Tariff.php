<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes; // 🔥 1. Імпорт трейту

class Tariff extends Model
{
    // 🔥 2. Підключення м'якого видалення
    use SoftDeletes;

    // Дозволяємо масове заповнення всіх полів
    protected $guarded = [];

    protected static function booted(): void
    {
        // Коли створили новий тариф — для кожного існуючого діапазону калорій
        // одразу робимо рядок ціни (0₴), щоб менеджер бачив повну матрицю.
        static::created(function (Tariff $tariff) {
            foreach (CalorieRange::all() as $range) {
                TariffPrice::firstOrCreate(
                    ['tariff_id' => $tariff->id, 'calorie_range_id' => $range->id],
                    ['price_per_day' => 0]
                );
            }
        });
    }

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

    public function projectData(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project', 'slug');
    }

    /**
     * План меню за замовчуванням — підставляється у нове замовлення з цим тарифом.
     */
    public function defaultMenuPlan(): BelongsTo
    {
        return $this->belongsTo(MenuPlan::class, 'default_menu_plan_id');
    }
}