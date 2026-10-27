<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * «Цей контрагент — завжди ось це» (як правила в Saldo). Ключ — ЄДРПОУ/ІПН,
 * без нього IBAN, без нього точне імʼя з виписки.
 */
class CounterpartyRule extends Model
{
    public const EXPENSE = 'expense';
    public const INCOME  = 'income';
    public const SALARY  = 'salary';
    public const IGNORE  = 'ignore';

    protected $guarded = [];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** Правило для операції банку або null. */
    public static function for(BankOperation $op): ?self
    {
        $direction = (float) $op->amount > 0 ? 'in' : 'out';
        $q = static::query()->where('direction', $direction);

        return match (true) {
            filled($op->counter_edrpou) => (clone $q)->where('edrpou', $op->counter_edrpou)->first(),
            filled($op->counter_iban)   => (clone $q)->where('iban', $op->counter_iban)->first(),
            filled($op->counter_name)   => (clone $q)->whereNull('edrpou')->whereNull('iban')->where('name', $op->counter_name)->first(),
            default                     => null,
        };
    }

    /** Запамʼятати контрагента цієї операції. */
    public static function remember(BankOperation $op, array $what, ?int $userId): self
    {
        $key = match (true) {
            filled($op->counter_edrpou) => ['edrpou' => $op->counter_edrpou, 'iban' => null, 'name' => null],
            filled($op->counter_iban)   => ['edrpou' => null, 'iban' => $op->counter_iban, 'name' => null],
            default                     => ['edrpou' => null, 'iban' => null, 'name' => $op->counter_name],
        };

        return static::updateOrCreate(
            $key + ['direction' => (float) $op->amount > 0 ? 'in' : 'out'],
            $what + ['created_by' => $userId, 'name' => $op->counter_name],
        );
    }
}
