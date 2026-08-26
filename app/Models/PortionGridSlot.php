<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Один прийом усередині тарифу: який саме і якого розміру.
 */
class PortionGridSlot extends Model
{
    protected $guarded = [];

    public function portionGrid(): BelongsTo
    {
        return $this->belongsTo(PortionGrid::class);
    }

    public function mealType(): BelongsTo
    {
        return $this->belongsTo(MealType::class);
    }

    public function isLarge(): bool
    {
        return $this->size === PortionGrid::SIZE_LARGE;
    }

    /** Енергія цього бокса за поточними налаштуваннями прийому. */
    public function boxKcal(): int
    {
        if (! $this->mealType) {
            return 0;
        }

        return (int) ($this->isLarge()
            ? $this->mealType->box_kcal_large
            : $this->mealType->box_kcal_std);
    }

    public function sizeLabel(): string
    {
        return $this->isLarge() ? 'L' : 'Std';
    }
}
