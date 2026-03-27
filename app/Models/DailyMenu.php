<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class DailyMenu extends Model
{
    use HasFactory;

    protected $fillable = [
        'day_number',
        'target_kcal',
        'cached_cost_950',
        'cached_cost_1500',
        'cached_cost_2500',
    ];

    protected $casts = [
        'day_number'       => 'integer',
        'target_kcal'      => 'integer',
        'cached_cost_950'  => 'float',
        'cached_cost_1500' => 'float',
        'cached_cost_2500' => 'float',
    ];

    public function menuItems(): HasMany
    {
        return $this->hasMany(DailyMenuDish::class, 'daily_menu_id');
    }
}
