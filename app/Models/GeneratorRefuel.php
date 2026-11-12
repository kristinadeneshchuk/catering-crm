<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Заправка генератора: літри, ціна, сума, кому оплачено. */
class GeneratorRefuel extends Model
{
    protected $guarded = [];

    protected $casts = [
        'date' => 'date', 'liters' => 'float', 'price_per_liter' => 'float',
        'amount' => 'float', 'engine_hours' => 'float',
    ];

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
