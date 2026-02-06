<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderReplacement extends Model
{
    use HasFactory;

    protected $guarded = [];

    // Зв'язок із замовленням
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    // Зв'язок зі стравою
    public function dish(): BelongsTo
    {
        return $this->belongsTo(Dish::class);
    }

    // Що замінюємо (посилання на Ingredients, бо таблиці products немає)
    public function originalProduct(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class, 'original_product_id');
    }

    // На що замінюємо
    public function replacementProduct(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class, 'replacement_product_id');
    }

    public function replacementDish(): BelongsTo
{
    return $this->belongsTo(Dish::class, 'replacement_dish_id');
}
}