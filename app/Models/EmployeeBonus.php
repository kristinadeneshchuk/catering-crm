<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class EmployeeBonus extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'employee_id',
        'amount',
        'reason',
        'date',
        'created_by',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'date'   => 'date',
    ];

    protected static function booted(): void
    {
        // При створенні премії — збільшуємо баланс співробітника (компанія
        // винна йому більше зарплати).
        static::created(function (self $bonus) {
            if ($bonus->employee) {
                $bonus->employee->increment('balance', (float) $bonus->amount);
            }
        });

        // При зміні суми премії — коректуємо balance на різницю.
        static::updated(function (self $bonus) {
            if ($bonus->employee && $bonus->wasChanged('amount')) {
                $old = (float) $bonus->getOriginal('amount');
                $new = (float) $bonus->amount;
                $diff = $new - $old;
                if (abs($diff) > 0.001) {
                    $bonus->employee->increment('balance', $diff);
                }
            }
        });

        // При скасуванні (soft delete) — забираємо суму з балансу.
        static::deleted(function (self $bonus) {
            if ($bonus->employee && !$bonus->isForceDeleting()) {
                $bonus->employee->decrement('balance', (float) $bonus->amount);
            }
        });

        // Якщо премію відновили з кошика — знов додаємо до балансу.
        static::restored(function (self $bonus) {
            if ($bonus->employee) {
                $bonus->employee->increment('balance', (float) $bonus->amount);
            }
        });
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
