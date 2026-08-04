<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Transaction extends Model
{
    // 🔥 ДОДАНО employee_id
    protected $fillable = [
        'order_id',
        'employee_id',
        'stock_document_id',
        'amount',
        'account_id',
        'type',
        'category',
        'date',
        'comment',
        'user_id'
    ];

    protected $casts = [
        'date' => 'date',
        'amount' => 'decimal:2',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function stockDocument(): BelongsTo
    {
        return $this->belongsTo(StockDocument::class);
    }

    // === ЛОГІКА БАЛАНСУ ТА АВТО-ОПЛАТИ ===
    protected static function booted()
    {
        // Будь-яка зміна транзакції клієнта → перерахувати баланс і FIFO статуси.
        // Не використовуємо increment/decrement, щоб поле balance не дрейфувало —
        // syncBalance() завжди обчислює з нуля за формулою.
        $syncClient = function ($transaction) {
            if ($transaction->order && $transaction->order->client) {
                $transaction->order->client->syncBalance();
                $transaction->order->client->recalculateOrderPaymentStatus();
            }
        };

        static::created(function ($transaction) use ($syncClient) {
            $syncClient($transaction);

            // Виплата ЗП: списуємо з "Боргу компанії" тут (єдина точка), а не в
            // кнопках "Виплатити" — щоб транзакція, створена будь-де (кнопка,
            // Журнал транзакцій, код), однаково рухала баланс співробітника.
            if ($transaction->employee_id && $transaction->type === 'expense') {
                $transaction->employee()->decrement('balance', abs((float) $transaction->amount));
            }
        });

        static::updated(function ($transaction) use ($syncClient) {
            $syncClient($transaction);

            // Правка виплати ЗП (сума / співробітник / тип) — відкатуємо стару
            // й застосовуємо нову, інакше баланс дрейфує (так і з'явився борг,
            // якого ніхто не нараховував).
            if ($transaction->wasChanged(['amount', 'employee_id', 'type'])) {
                $oldEmp  = $transaction->getOriginal('employee_id');
                $oldAmt  = abs((float) $transaction->getOriginal('amount'));
                $oldType = $transaction->getOriginal('type');

                if ($oldEmp && $oldType === 'expense') {
                    Employee::find($oldEmp)?->increment('balance', $oldAmt);
                }
                if ($transaction->employee_id && $transaction->type === 'expense') {
                    $transaction->employee()->decrement('balance', abs((float) $transaction->amount));
                }
            }
        });

        static::deleted(function ($transaction) use ($syncClient) {
            $syncClient($transaction);

            // Якщо це була виплата зарплати (співробітник) — повертаємо суму у "Борг компанії"
            if ($transaction->employee_id && $transaction->type === 'expense') {
                $transaction->employee()->increment('balance', abs((float) $transaction->amount));
            }
        });
    }
}