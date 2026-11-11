<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LunchOrderLine extends Model
{
    protected $guarded = [];

    public const KINDS = ['main' => 'Основна', 'light' => 'Легка', 'extra' => 'Доплата'];

    public function order(): BelongsTo
    {
        return $this->belongsTo(LunchOrder::class, 'lunch_order_id');
    }

    public function dish(): BelongsTo
    {
        return $this->belongsTo(Dish::class);
    }
}
