<?php

namespace App\Models;

use App\Models\Transaction;
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

    public function account()
    {
        return $this->belongsTo(\App\Models\Account::class);
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

    protected static function booted()
    {
        static::deleting(function ($document) {
            foreach ($document->items as $item) {
                $item->revertStock();
            }
            Transaction::where('stock_document_id', $document->id)->delete();
        });
    }

    public function syncTransaction(): void
    {
        $doc = $this->fresh();
        if (!$doc || $doc->total_sum <= 0) {
            return;
        }

        $isReceipt = $doc->type === 'receipt';
        $supplierName = $doc->supplier?->name;
        $comment = "Документ #{$doc->id}" . ($supplierName ? " від {$supplierName}" : '');

        $accountId = $doc->account_id
            ?? \App\Models\Account::where('is_default', true)->value('id');

        Transaction::updateOrCreate(
            ['stock_document_id' => $doc->id],
            [
                'type'       => $isReceipt ? 'expense' : 'income',
                'category'   => $isReceipt ? 'Закупівля' : 'Списання зі складу',
                'amount'     => $doc->total_sum,
                'account_id' => $accountId,
                'date'       => $doc->operation_date,
                'comment'    => $comment,
                'user_id'    => auth()->id(),
            ]
        );
    }
}