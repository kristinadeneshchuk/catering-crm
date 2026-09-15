<?php

namespace App\Models;

use App\Models\Transaction;
use Illuminate\Database\Eloquent\Model;

class StockDocument extends Model
{
    /**
     * Чернетка видна в CRM, але нічого не рухає: ні залишків, ні середньої
     * ціни інгредієнта, ні каси. Так ШІ і люди можуть класти накладні й
     * списання «на перевірку». Проводить лише адмін — див. post().
     */
    public const STATUS_DRAFT  = 'draft';
    // Так документи зберігаються з лютого (default колонки) — тобто «проведено».
    public const STATUS_POSTED = 'completed';

    public const SOURCE_MANUAL = 'manual';
    public const SOURCE_AI     = 'ai';

    protected $guarded = [];

    protected $casts = [
        'operation_date' => 'datetime',
        'posted_at'      => 'datetime',
        'attachments'    => 'array',
    ];

    public static function statusLabels(): array
    {
        return [
            self::STATUS_DRAFT  => 'Чернетка',
            self::STATUS_POSTED => 'Проведено',
        ];
    }

    /** Документи, що вже впливають на склад і гроші. */
    public function scopePosted($query)
    {
        return $query->where($query->qualifyColumn('status'), '!=', self::STATUS_DRAFT);
    }

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    /**
     * Провести чернетку: застосувати позиції до складу, записати оплату,
     * перерахувати собівартість меню. Рівно один раз — повторний виклик на
     * проведеному документі нічого не робить.
     */
    public function post(?int $userId = null): bool
    {
        return \Illuminate\Support\Facades\DB::transaction(function () use ($userId) {
            $doc = static::lockForUpdate()->find($this->id);

            if (! $doc || ! $doc->isDraft()) {
                return false;
            }

            $doc->forceFill([
                'status'    => self::STATUS_POSTED,
                'posted_at' => now(),
                'posted_by' => $userId,
            ])->saveQuietly();

            foreach ($doc->items()->get() as $item) {
                $item->setRelation('stockDocument', $doc);
                $item->applyStock();
            }

            $doc->updateTotalSum();
            $doc->syncTransaction();

            if ($doc->type === 'receipt') {
                \App\Models\Ingredient::clearAveragePriceCache();
                \App\Jobs\RecalculateDailyMenuCosts::dispatchAfterResponse();
            }

            $this->refresh();

            return true;
        });
    }

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

        // Чернетка в касу не потрапляє, навіть якщо позначена оплаченою:
        // гроші рухаються лише після проведення.
        if ($doc->isDraft()) return;

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