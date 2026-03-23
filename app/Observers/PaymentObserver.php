<?php

namespace App\Observers;

use App\Models\Transaction; // ✅ Виправлено з Payment
use App\Models\Account;

class PaymentObserver
{
    /**
     * Спрацьовує при створенні транзакції.
     */
    public function created(Transaction $transaction): void
    {
        if ($transaction->account_id) {
            $account = Account::find($transaction->account_id);
            if ($account) {
                // Якщо це дохід (клієнт платить нам) -> додаємо гроші на рахунок
                // Якщо це витрата/повернення -> віднімаємо
                if ($transaction->type === 'income') {
                    $account->increment('balance', $transaction->amount);
                } else {
                    $account->decrement('balance', $transaction->amount);
                }
            }
        }
    }

    /**
     * Спрацьовує при зміні (наприклад, змінили суму або рахунок).
     */
    public function updated(Transaction $transaction): void
    {
        // Логіка перерахунку при зміні рахунку або суми
        // Для спрощення, якщо сума змінилась - просто оновимо баланс
        // (Тут можна розширити логіку, але для початку цього вистачить)
        if ($transaction->isDirty('amount') && $transaction->account_id) {
            $diff = $transaction->amount - $transaction->getOriginal('amount');
            $account = Account::find($transaction->account_id);
            
            if ($account) {
                if ($transaction->type === 'income') {
                    $diff >= 0
                        ? $account->increment('balance', $diff)
                        : $account->decrement('balance', abs($diff));
                } else {
                    $diff >= 0
                        ? $account->decrement('balance', $diff)
                        : $account->increment('balance', abs($diff));
                }
            }
        }
    }

    /**
     * Спрацьовує при видаленні транзакції.
     */
    public function deleted(Transaction $transaction): void
    {
        if ($transaction->account_id) {
            $account = Account::find($transaction->account_id);
            if ($account) {
                // Робимо навпаки: якщо видалили дохід, гроші з рахунку зникають
                if ($transaction->type === 'income') {
                    $account->decrement('balance', $transaction->amount);
                } else {
                    $account->increment('balance', $transaction->amount);
                }
            }
        }
    }
}