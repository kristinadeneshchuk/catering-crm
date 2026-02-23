<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StockDocument extends Model
{
    protected $guarded = [];

    protected $casts = [
        'operation_date' => 'datetime',
    ];

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }

    public function items()
    {
        return $this->hasMany(StockDocumentItem::class);
    }

    public function updateTotalSum()
    {
        $this->total_sum = $this->items()->sum('total_price');
        $this->saveQuietly(); 
    }

    // 🔥 ДОДАЄМО ЦЕЙ БЛОК:
    protected static function booted()
    {
        // Перед видаленням самого документа...
        static::deleting(function ($document) {
            // ...проходимося по всіх його товарах і повертаємо залишки
            foreach ($document->items as $item) {
                $item->revertStock();
            }
        });
    }
}