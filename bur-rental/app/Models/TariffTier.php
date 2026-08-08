<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Рівень тарифної сходинки. Signature-елемент сервісу: чим довше строк,
 * тим дешевше день. Економія рахується від базового (найдорожчого) рівня.
 */
class TariffTier extends Model
{
    protected $guarded = [];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function covers(int $days): bool
    {
        return $days >= $this->min_days && $days <= ($this->max_days ?? PHP_INT_MAX);
    }
}
