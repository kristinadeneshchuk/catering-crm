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

        // Якщо змінили is_paid → синхронізуємо транзакцію
        static::updated(function ($document) {
            if ($document->wasChanged('is_paid')) {
                $document->syncTransaction();
            }
        });
    }

    public function syncTransaction(): void
    {
        $doc = $this->fresh();
        if (!$doc) return;

        // Якщо не оплачено — залишаємо оригінал у журналі, відновлюємо баланс
        if (!$doc->is_paid) {
            $existing = Transaction::where('stock_document_id', $doc->id)->first();
            if ($existing) {
                $supplierName = $doc->supplier?->name;

                // Відв'язуємо оригінал від документу і додаємо позначку (залишається в журналі)
                $existing->updateQuietly([
                    'stock_document_id' => null,
                    'comment'           => $existing->comment . ' (скасовано ' . now()->format('d.m.Y') . ')',
                ]);

                // Створюємо зворотну проводку — відновлює баланс рахунку
                Transaction::create([
                    'type'              => $existing->type === 'expense' ? 'income' : 'expense',
                    'category'          => 'Скасування оплати',
                    'amount'            => $existing->amount,
                    'account_id'        => $existing->account_id,
                    'date'              => now(),
                    'comment'           => "Скасування оплати: Документ #{$doc->id}" . ($supplierName ? " від {$supplierName}" : ''),
                    'user_id'           => auth()->id(),
                    'stock_document_id' => null,
                ]);
            }
            return;
        }

        if ($doc->total_sum <= 0) return;

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