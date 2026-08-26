<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Тариф у сітці порцій: які прийоми входять і якого розміру кожен.
 */
class PortionGrid extends Model
{
    protected $guarded = [];

    protected $casts = [
        'calories'           => 'integer',
        'extra_snacks_std'   => 'integer',
        'extra_snacks_large' => 'integer',
        'is_active'          => 'boolean',
    ];

    public const SIZE_STD   = 'std';
    public const SIZE_LARGE = 'large';

    public function slots(): HasMany
    {
        return $this->hasMany(PortionGridSlot::class);
    }

    /**
     * Скільки кілокалорій дає цей тариф за поточними розмірами боксів.
     *
     * Має дорівнювати самому тарифу. Якщо ні — сітку зібрали з помилкою, і
     * краще сказати про це в адмінці, ніж випустити у виробництво.
     */
    public function actualKcal(): int
    {
        $slots = $this->relationLoaded('slots') ? $this->slots : $this->slots()->with('mealType')->get();

        $total = 0;
        $snackStd = $snackLarge = 0;

        foreach ($slots as $slot) {
            $total += $slot->boxKcal();

            // Додатковий снек — друга порція перекусу, тож і рахуємо за ним.
            if ($slot->mealType && $slot->mealType->box_kcal_std) {
                $snackStd = max($snackStd, (int) $slot->mealType->box_kcal_std);
                $snackLarge = max($snackLarge, (int) $slot->mealType->box_kcal_large);
            }
        }

        $snack = $this->snackBox();

        if ($snack) {
            $total += $this->extra_snacks_std * (int) $snack->box_kcal_std;
            $total += $this->extra_snacks_large * (int) $snack->box_kcal_large;
        }

        return $total;
    }

    /**
     * Прийом, з якого беруться додаткові снеки — найлегший бокс у тарифі.
     * За домовленістю це перекус: «додатковий снек = друга порція перекусу дня».
     */
    public function snackBox(): ?MealType
    {
        $slots = $this->relationLoaded('slots') ? $this->slots : $this->slots()->with('mealType')->get();

        return $slots
            ->map->mealType
            ->filter(fn (?MealType $m) => $m && $m->box_kcal_std)
            ->sortBy('box_kcal_std')
            ->first();
    }

    public function isBalanced(): bool
    {
        return $this->actualKcal() === (int) $this->calories;
    }

    /** Тариф для заданого калоражу. */
    public static function forCalories(int $calories): ?self
    {
        return static::with('slots.mealType')
            ->where('is_active', true)
            ->where('calories', $calories)
            ->first();
    }
}
