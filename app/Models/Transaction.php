<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Transaction extends Model
{
    protected $fillable = ['order_id', 'amount', 'account_id', 'type', 'date', 'comment', 'user_id'];

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

    // === ЛОГІКА БАЛАНСУ ТА АВТО-ОПЛАТИ ===
    protected static function booted()
    {
        // Коли створили транзакцію (внесли гроші або списали на ЗП)
        static::created(function ($transaction) {
            // 🔥 ВИПРАВЛЕННЯ: Додано перевірку, чи транзакція пов'язана із замовленням
            if ($transaction->order) {
                $client = $transaction->order->client;
                if ($client) {
                    // 1. Оновлюємо загальний баланс клієнта
                    if ($transaction->type === 'income') {
                        $client->increment('balance', $transaction->amount);
                    } else {
                        $client->decrement('balance', $transaction->amount);
                    }

                    // 2. ВАЖЛИВО: Запускаємо перерахунок статусів замовлень клієнта (FIFO)
                    $client->recalculateOrderPaymentStatus();
                }
            }
        });

        // Коли видалили транзакцію (скасували оплату)
        static::deleted(function ($transaction) {
            // 🔥 ВИПРАВЛЕННЯ: Додано перевірку, чи була транзакція пов'язана із замовленням
            if ($transaction->order) {
                $client = $transaction->order->client;
                if ($client) {
                    // 1. Відкочуємо баланс клієнта назад
                    if ($transaction->type === 'income') {
                        $client->decrement('balance', $transaction->amount);
                    } else {
                        $client->increment('balance', $transaction->amount);
                    }

                    // 2. Знову перераховуємо статуси оплат
                    $client->recalculateOrderPaymentStatus();
                }
            }
        });
    }
}