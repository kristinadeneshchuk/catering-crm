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
        'amount', 
        'account_id', 
        'type', 
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

    // 🔥 ДОДАНО зв'язок зі співробітником
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    // === ЛОГІКА БАЛАНСУ ТА АВТО-ОПЛАТИ ===
    protected static function booted()
    {
        // Коли створили транзакцію
        static::created(function ($transaction) {
            // Логіка для клієнтів (залишаємо як була)
            if ($transaction->order) {
                $client = $transaction->order->client;
                if ($client) {
                    if ($transaction->type === 'income') {
                        $client->increment('balance', $transaction->amount);
                    } else {
                        $client->decrement('balance', $transaction->amount);
                    }
                    $client->recalculateOrderPaymentStatus();
                }
            }
            
            // Примітка: Для співробітників баланс (борг) ми зменшуємо вручну в EmployeeResource,
            // щоб мати повний контроль над процесом виплати.
        });

        // Коли видалили транзакцію (СКАСУВАННЯ ПОМИЛКИ)
        static::deleted(function ($transaction) {
            // 1. Якщо це була транзакція замовлення (клієнт)
            if ($transaction->order) {
                $client = $transaction->order->client;
                if ($client) {
                    if ($transaction->type === 'income') {
                        $client->decrement('balance', $transaction->amount);
                    } else {
                        $client->increment('balance', $transaction->amount);
                    }
                    $client->recalculateOrderPaymentStatus();
                }
            }

            // 🔥 2. НОВА ЛОГІКА: Якщо це була виплата зарплати (співробітник)
            if ($transaction->employee_id && $transaction->type === 'expense') {
                // Повертаємо суму назад у "Борг компанії"
                $transaction->employee()->increment('balance', $transaction->amount);
            }
        });
    }
}