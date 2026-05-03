<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class DailyMenu extends Model
{
    use HasFactory;

    protected $fillable = [
        'menu_plan_id',
        'day_number',
        'target_kcal',
        'target_protein_g',
        'target_fat_g',
        'target_carb_g',
        'cached_cost_950',
        'cached_cost_1500',
        'cached_cost_2500',
    ];

    protected $casts = [
        'day_number'       => 'integer',
        'target_kcal'      => 'integer',
        'target_protein_g' => 'integer',
        'target_fat_g'     => 'integer',
        'target_carb_g'    => 'integer',
        'cached_cost_950'  => 'float',
        'cached_cost_1500' => 'float',
        'cached_cost_2500' => 'float',
    ];

    public function menuItems(): HasMany
    {
        return $this->hasMany(DailyMenuDish::class, 'daily_menu_id');
    }

    public function menuPlan(): BelongsTo
    {
        return $this->belongsTo(MenuPlan::class);
    }
}
