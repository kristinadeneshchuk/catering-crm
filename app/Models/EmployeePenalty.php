<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class EmployeePenalty extends Model
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
        // При створенні штрафу — зменшуємо балансу співробітнику (компанія
        // винна йому менше зарплати).
        static::created(function (self $penalty) {
            if ($penalty->employee) {
                $penalty->employee->decrement('balance', (float) $penalty->amount);
            }
        });

        // При зміні суми штрафу — коректуємо balance на різницю.
        static::updated(function (self $penalty) {
            if ($penalty->employee && $penalty->wasChanged('amount')) {
                $old = (float) $penalty->getOriginal('amount');
                $new = (float) $penalty->amount;
                $diff = $new - $old;
                if (abs($diff) > 0.001) {
                    $penalty->employee->decrement('balance', $diff);
                }
            }
        });

        // При скасуванні (soft delete) — повертаємо суму на баланс.
        static::deleted(function (self $penalty) {
            if ($penalty->employee && !$penalty->isForceDeleting()) {
                $penalty->employee->increment('balance', (float) $penalty->amount);
            }
        });

        // Якщо штраф відновили з кошика — знов забираємо суму з балансу.
        static::restored(function (self $penalty) {
            if ($penalty->employee) {
                $penalty->employee->decrement('balance', (float) $penalty->amount);
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
