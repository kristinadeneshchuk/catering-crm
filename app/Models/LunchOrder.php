<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Обіди однієї компанії на один день — знімок, який Lunch Hub надсилає після
 * дедлайну. Повторне надсилання дня перезаписує його повністю.
 */
class LunchOrder extends Model
{
    protected $guarded = [];

    protected $casts = [
        'date'        => 'date',
        'amount'      => 'decimal:2',
        'received_at' => 'datetime',
        'people'      => 'array',
    ];

    public function lines(): HasMany
    {
        return $this->hasMany(LunchOrderLine::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }
}
