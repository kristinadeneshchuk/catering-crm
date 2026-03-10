<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DailyMenuDish extends Model
{
    public $timestamps = false; 

    // 🔥 Додали 'custom_energy_percent'
    protected $fillable = ['daily_menu_id', 'dish_id', 'meal_type_id', 'custom_energy_percent'];

    public function dailyMenu(): BelongsTo
    {
        return $this->belongsTo(DailyMenu::class);
    }

    public function dish(): BelongsTo
    {
        return $this->belongsTo(Dish::class);
    }

    public function mealType(): BelongsTo
    {
        return $this->belongsTo(MealType::class);
    }
}