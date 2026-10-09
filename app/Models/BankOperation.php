<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Операція з виписки банку (monobank). Лише для супер адміна. */
class BankOperation extends Model
{
    protected $guarded = [];

    protected $casts = [
        'operated_at'   => 'datetime',
        'amount'        => 'decimal:2',
        'balance_after' => 'decimal:2',
        'raw'           => 'array',
    ];

    /** Вікно, в якому зустрічна операція на іншому своєму рахунку = переказ між своїми. */
    public const INTERNAL_WINDOW_SEC = 900;

    /**
     * Переказ між своїми рахунками (ФОП → біла картка тощо). Лише позначка для
     * «Банку», щоб витрати не рахувались двічі; оплати в CRM не чіпає.
     * Своя, якщо: контрагент — IBAN підключеного рахунку, або на іншому
     * підключеному рахунку є зустрічна сума з протилежним знаком у межах 15 хв.
     */
    public function scopeInternal(Builder $q, bool $internal = true): Builder
    {
        $table = $this->getTable();
        $diff = $q->getConnection()->getDriverName() === 'sqlite'
            ? "abs((julianday(b.operated_at) - julianday({$table}.operated_at)) * 86400)"
            : "abs(timestampdiff(second, b.operated_at, {$table}.operated_at))";

        $condition = function (Builder $w) use ($table, $diff) {
            // NULL-безпечно: інакше NOT(...) відкидає операції без IBAN контрагента.
            $w->where(fn ($i) => $i->whereNotNull("{$table}.counter_iban")
                    ->whereIn("{$table}.counter_iban", Account::withBankOnly()->whereNotNull('mono_iban')->select('mono_iban')))
                ->orWhereExists(fn ($e) => $e->selectRaw('1')
                    ->from("{$table} as b")
                    ->whereColumn('b.account_id', '!=', "{$table}.account_id")
                    ->whereRaw("b.amount = -{$table}.amount")
                    ->whereRaw("{$diff} <= " . self::INTERNAL_WINDOW_SEC));
        };

        return $internal ? $q->where($condition) : $q->whereNot($condition);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class)->withoutGlobalScope('payable');
    }
}
