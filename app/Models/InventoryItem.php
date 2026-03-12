<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class InventoryItem extends Model
{
    protected $fillable = [
        'inventory_id',
        'itemable_type',
        'itemable_id',
        'name',
        'unit',
        'expected_qty',
        'actual_qty',
        'price',
    ];

    public function inventory(): BelongsTo
    {
        return $this->belongsTo(Inventory::class);
    }

    // Зв'язок з конкретним Інгредієнтом або Упаковкою
    public function itemable(): MorphTo
    {
        return $this->morphTo();
    }
}