<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Kit extends Model
{
    protected $guarded = [];

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function items(): HasMany
    {
        return $this->hasMany(KitItem::class)->orderBy('position');
    }

    /** Сума позицій окремо — з неї рахується економія комплекту. */
    public function itemsSum(int $days = 1): int
    {
        return $this->items->sum(fn (KitItem $item) => $item->product->priceFor($days) * $days);
    }

    public function priceFor(int $days = 1): int
    {
        return (int) round($this->itemsSum($days) * (100 - $this->discount_percent) / 100);
    }
}
