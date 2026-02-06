<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class DailyMenu extends Model
{
    use HasFactory;

    /**
     * Масив дозволених полів для Mass Assignment.
     * ЗАМІНА: 'date' на 'day_number'.
     */
    protected $fillable = [
        'day_number', // Тепер це головне поле для циклічного меню
    ];

    /**
     * Перетворення типів (Casting).
     * ГАРАНТІЯ: 'day_number' завжди буде цілим числом.
     */
    protected $casts = [
        'day_number' => 'integer',
    ];

    /**
     * Список усіх страв, запланованих на цей день циклу.
     * Зв'язок залишається без змін.
     */
    public function menuItems(): HasMany
    {
        return $this->hasMany(DailyMenuDish::class, 'daily_menu_id');
    }
}