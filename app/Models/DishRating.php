<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DishRating extends Model
{
    protected $fillable = [
        'order_id',
        'dish_id',
        'date',
        'stars',
        'comment',
    ];

    protected $casts = [
        'date'  => 'date',
        'stars' => 'integer',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function dish(): BelongsTo
    {
        return $this->belongsTo(Dish::class);
    }
}
