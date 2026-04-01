<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class MealType extends Model
{
    protected $guarded = [];

    protected $casts = [
        'sort_order'     => 'integer',
        'energy_percent' => 'float',
    ];

    /**
     * Зв'язок: Тип прийому їжі -> Клієнти, які його обрали
     */
    public function clients(): BelongsToMany
    {
        return $this->belongsToMany(Client::class, 'client_meal_type');
    }
}