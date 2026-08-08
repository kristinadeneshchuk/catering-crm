<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Booking extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['date_from' => 'date', 'date_to' => 'date'];
    }

    public function getRouteKeyName(): string
    {
        return 'number';
    }

    public function items(): HasMany
    {
        return $this->hasMany(BookingItem::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function deliveryZone(): BelongsTo
    {
        return $this->belongsTo(DeliveryZone::class);
    }

    /** До сплати зараз: оренда + витратники + доставка + застава. */
    public function getPayableAttribute(): int
    {
        return $this->rent_total + $this->extras_total + $this->delivery_total + $this->deposit_total;
    }

    public function getDaysAttribute(): int
    {
        return $this->date_from->diffInDays($this->date_to) + 1;
    }
}
