<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Операція з виписки банку (monobank). Лише для супер адміна. */
class BankOperation extends Model
{
    protected $guarded = [];

    protected $casts = [
        'operated_at'   => 'datetime',
        'amount'        => 'decimal:2',
        'balance_after' => 'decimal:2',
        'raw'           => 'array',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}
