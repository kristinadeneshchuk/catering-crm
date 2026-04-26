<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class MealPlan extends Model
{
    protected $fillable = ['name', 'min_kcal', 'max_kcal'];

    protected $casts = [
        'min_kcal' => 'integer',
        'max_kcal' => 'integer',
    ];

    public function mealTypes(): BelongsToMany
    {
        return $this->belongsToMany(MealType::class, 'meal_plan_meal_type')
            ->orderBy('sort_order');
    }

    /**
     * Знайти план (з прийомами їжі) для заданої калорійності
     */
    public static function findForKcal(int $kcal): ?self
    {
        static $cache = [];
        if (!array_key_exists($kcal, $cache)) {
            $cache[$kcal] = self::with('mealTypes')
                ->where('min_kcal', '<=', $kcal)
                ->where('max_kcal', '>=', $kcal)
                ->first();
        }
        return $cache[$kcal];
    }

    /**
     * Отримати sort_order прийомів їжі для заданої калорійності
     */
    public static function getAllowedSortOrders(int $kcal): array
    {
        $plan = self::findForKcal($kcal);
        if (!$plan) return [1, 3, 5]; // дефолт: 3 страви
        return $plan->mealTypes->pluck('sort_order')->sort()->values()->all();
    }

    /**
     * Отримати ID прийомів їжі для заданої калорійності
     */
    public static function getAllowedMealTypeIds(int $kcal): array
    {
        $plan = self::findForKcal($kcal);
        if (!$plan) return [];
        return $plan->mealTypes->pluck('id')->all();
    }
}
