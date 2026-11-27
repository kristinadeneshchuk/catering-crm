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
     * Зміна суми, типу або рахунку: знімаємо старий вплив на залишок і кладемо новий.
     * Раніше рахувалась лише зміна суми — оплата, якій рахунок вказали пізніше
     * (або перенесли на інший), у залишок не потрапляла.
     */
    public function updated(Transaction $transaction): void
    {
        if (! $transaction->wasChanged(['amount', 'type', 'account_id'])) {
            return;
        }

        $this->apply($transaction->getOriginal('account_id'), $transaction->getOriginal('type'), -(float) $transaction->getOriginal('amount'));
        $this->apply($transaction->account_id, $transaction->type, (float) $transaction->amount);
    }

    /** Надходження збільшує залишок, усе інше зменшує; $amount < 0 — відкат. */
    private function apply($accountId, ?string $type, float $amount): void
    {
        if (! $accountId || abs($amount) < 0.005) {
            return;
        }
        $signed = $type === 'income' ? $amount : -$amount;
        Account::whereKey($accountId)->increment('balance', $signed);
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