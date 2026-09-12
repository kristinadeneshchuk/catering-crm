<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Виплата курʼєру за день — знімок розрахунку, погоджений у Telegram.
 *
 * Сама по собі employees.balance не рухає: гроші вже нараховані чинною логікою
 * (Табель, пробіг, бонуси, штрафи). Це шар погодження поверх неї.
 *
 * @see \App\Services\Couriers\CourierPayoutCalculator
 * @see \App\Services\Couriers\CourierPayoutService
 */
class CourierPayout extends Model
{
    public const STATUS_DRAFT    = 'draft';
    public const STATUS_SENT     = 'sent';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_PAID     = 'paid';

    protected $fillable = [
        'employee_id', 'date', 'shift_slot', 'delivery_route_id', 'mileage_log_id',
        'components', 'total', 'cash_on_hand', 'to_pay', 'status',
        'approved_by_tg', 'approved_by', 'approved_at', 'paid_at',
        'tg_messages', 'reject_reason', 'comment',
    ];

    protected $casts = [
        'components'   => 'array',
        'tg_messages'  => 'array',
        'total'        => 'decimal:2',
        'cash_on_hand' => 'decimal:2',
        'to_pay'       => 'decimal:2',
        'approved_at'  => 'datetime',
        'paid_at'      => 'datetime',
    ];

    public static function statusLabels(): array
    {
        return [
            self::STATUS_DRAFT    => 'Чернетка',
            self::STATUS_SENT     => 'На погодженні',
            self::STATUS_APPROVED => 'Погоджено',
            self::STATUS_REJECTED => 'Відхилено',
            self::STATUS_PAID     => 'Виплачено',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function dateString(): string
    {
        return \Carbon\Carbon::parse($this->getRawOriginal('date') ?? $this->date)->format('Y-m-d');
    }

    /** Погоджену чи виплачену суму вже не перераховуємо: це зафіксоване рішення. */
    public function isLocked(): bool
    {
        return in_array($this->status, [self::STATUS_APPROVED, self::STATUS_PAID], true);
    }
}
