<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StockDocumentItem extends Model
{
    protected $guarded = [];

    public function stockDocument()
    {
        return $this->belongsTo(StockDocument::class, 'stock_document_id');
    }

    public function itemable()
    {
        return $this->morphTo();
    }

    protected static function booted()
    {
        static::created(function ($item) {
            $item->applyStock(); 
            if ($item->stockDocument) {
                $item->stockDocument->updateTotalSum();
            }
        });

        static::updating(function ($item) {
            $item->revertStock(); 
        });

        static::updated(function ($item) {
            $item->applyStock(); 
            if ($item->stockDocument) {
                $item->stockDocument->updateTotalSum();
            }
        });

        static::deleted(function ($item) {
            $item->revertStock(); 
            if ($item->stockDocument) {
                $item->stockDocument->updateTotalSum();
            }
        });
    }

    public function applyStock()
    {
        if (!$this->itemable || !$this->stockDocument) return;

        $qty = (float) $this->qty;
        $type = $this->stockDocument->type;

        if ($type === 'receipt') {
            // Тільки оновлюємо залишок, не чіпаючи базову ціну в інгредієнті
            $this->itemable->increment('stock', $qty);
        } elseif ($type === 'write_off') {
            $this->itemable->decrement('stock', $qty);
        } elseif ($type === 'inventory') {
            $this->itemable->stock = $qty;
            $this->itemable->save();
        }
    }

    public function revertStock()
    {
        $originalType = $this->getOriginal('itemable_type') ?: $this->itemable_type;
        $originalId = $this->getOriginal('itemable_id') ?: $this->itemable_id;
        $originalQty = (float) ($this->getOriginal('qty') ?: $this->qty);

        if (!$originalType || !$originalId || !$this->stockDocument) return;

        $itemable = $originalType::find($originalId);
        if (!$itemable) return;

        $type = $this->stockDocument->type;

        if ($type === 'receipt') {
            $itemable->decrement('stock', $originalQty);
        } elseif ($type === 'write_off') {
            $itemable->increment('stock', $originalQty);
        }
    }
}