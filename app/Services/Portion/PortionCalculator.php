<?php

namespace App\Services\Portion;

use App\Models\Dish;
use App\Models\MealType;
use App\Models\PortionGrid;
use App\Models\Setting;
use App\Traits\CalculatesOrderPlan;

/**
 * Друга версія фасування: у страви рівно дві ваги — звичайна і велика.
 *
 * Стара версія ділить калораж замовлення між прийомами у відсотках, тож вага
 * страви залежить від калоражу: скільки калоражів — стільки й ваг, до двадцяти
 * на одну страву. Саме на це скаржиться фасувальна станція.
 *
 * Тут енергія бокса задана в кілокалоріях прямо (Сніданок 400/600, Снек
 * 200/400 і так далі), тому вага рахується один раз:
 *
 *     вага = енергія бокса ÷ ккал на 100 г × 100 → округлення
 *
 * Усі правила — енергія боксів, крок округлення, допуск — налаштовуються в
 * CRM. У коді немає жодної зашитої цифри, яку доведеться міняти релізом.
 */
class PortionCalculator
{
    use CalculatesOrderPlan;

    /** Ключі налаштувань. Значення за замовчуванням — поточна домовленість. */
    public const KEY_ROUNDING   = 'portion_rounding_step';
    public const KEY_TOLERANCE  = 'portion_tolerance_percent';
    public const KEY_TOLERANCE_MIN = 'portion_tolerance_min_g';

    public const DEFAULT_ROUNDING      = 5;
    public const DEFAULT_TOLERANCE     = 3.0;
    public const DEFAULT_TOLERANCE_MIN = 5;

    /**
     * Вага і допуск страви в заданому боксі.
     *
     * @return array{weight: int, tolerance: int, kcal_box: int, kcal_per_100g: float}|null
     */
    public function portion(Dish $dish, MealType $mealType, bool $large): ?array
    {
        $boxKcal = (int) ($large ? $mealType->box_kcal_large : $mealType->box_kcal_std);

        if ($boxKcal <= 0) {
            return null;
        }

        $per100 = (float) $this->dishKcalPer100g($dish);

        if ($per100 <= 0) {
            return null;
        }

        $weight = $this->round($boxKcal / $per100 * 100);

        return [
            'weight'        => $weight,
            'tolerance'     => $this->tolerance($weight),
            'kcal_box'      => $boxKcal,
            'kcal_per_100g' => round($per100, 1),
        ];
    }

    /**
     * Обидві ваги страви для прийому — саме те, що бачить фасувальник.
     *
     * @return array{std: ?array, large: ?array}
     */
    public function bothPortions(Dish $dish, MealType $mealType): array
    {
        return [
            'std'   => $this->portion($dish, $mealType, false),
            'large' => $this->portion($dish, $mealType, true),
        ];
    }

    /** Крок округлення береться з налаштувань. */
    public function round(float $grams): int
    {
        $step = max(1, (int) $this->setting(self::KEY_ROUNDING, self::DEFAULT_ROUNDING));

        return (int) (round($grams / $step) * $step);
    }

    /**
     * Допуск «вилка ±»: відсоток від ваги, але не менше мінімуму.
     *
     * Мінімум потрібен для дрібних боксів: 3% від 80 г це 2 грами, а таку
     * точність на station не витримати.
     */
    public function tolerance(int $weight): int
    {
        $percent = (float) $this->setting(self::KEY_TOLERANCE, self::DEFAULT_TOLERANCE);
        $min     = (int) $this->setting(self::KEY_TOLERANCE_MIN, self::DEFAULT_TOLERANCE_MIN);

        return max($min, (int) round($weight * $percent / 100));
    }

    /**
     * Розмір кожного прийому для тарифу: meal_type_id => bool (велика чи ні).
     *
     * @return array<int, bool>
     */
    public function slotSizes(PortionGrid $grid): array
    {
        $out = [];

        foreach ($grid->slots as $slot) {
            $out[$slot->meal_type_id] = $slot->isLarge();
        }

        return $out;
    }

    private function setting(string $key, float|int $default): float|int
    {
        $value = Setting::where('key', $key)->value('value');

        return $value === null || $value === '' ? $default : (float) $value;
    }
}
