<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeliveryZone extends Model
{
    /** Ціна зони — це сума доставки (плюс доплати за вагу). */
    public const FIXED = 'fixed';

    /** Суму називає менеджер при підтвердженні: область, кілометраж. */
    public const QUOTE = 'quote';

    /** Рядок-правило для таблиці на сайті, у формі бронювання не вибирається. */
    public const INFO = 'info';

    protected $guarded = [];

    public function isQuote(): bool
    {
        return $this->price_mode === self::QUOTE;
    }

    /** Зони, які клієнт може вибрати при бронюванні. */
    public function scopeBookable(Builder $query): void
    {
        $query->where('price_mode', '!=', self::INFO);
    }

    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }
}
