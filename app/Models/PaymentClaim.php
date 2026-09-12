<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Заява про оплату: хтось повідомив, що гроші є. Сама по собі нічого не платить.
 *
 * is_paid змінює тільки транзакція, а транзакцію створює лише менеджер при
 * підтвердженні — PaymentClaimService::confirm().
 *
 * @see database/migrations/2026_09_12_120000_create_payment_claims_table.php
 */
class PaymentClaim extends Model
{
    public const SOURCE_CLIENT_TRANSFER = 'client_transfer';
    public const SOURCE_CLIENT_CASH     = 'client_cash';
    public const SOURCE_COURIER_CASH    = 'courier_cash';
    public const SOURCE_INVOICE_SENT    = 'invoice_sent';

    public const METHOD_CASH     = 'cash';
    public const METHOD_TRANSFER = 'transfer';

    public const REPORTED_BY_AGENT   = 'agent';
    public const REPORTED_BY_COURIER = 'courier';
    public const REPORTED_BY_MANAGER = 'manager';

    public const STATUS_PENDING    = 'pending';
    public const STATUS_CONFIRMED  = 'confirmed';
    public const STATUS_REJECTED   = 'rejected';
    public const STATUS_SUPERSEDED = 'superseded';

    /** Готівка — це дві заяви на одну суму: від клієнта і від курʼєра. */
    public const CASH_SOURCES = [self::SOURCE_CLIENT_CASH, self::SOURCE_COURIER_CASH];

    /** Копійчана похибка: суми decimal(10,2), порівнювати «в лоб» не можна. */
    public const MONEY_EPSILON = 0.001;

    protected $fillable = [
        'order_id', 'client_id', 'source', 'amount', 'method',
        'reported_by_type', 'reported_by_id',
        'employee_id', 'order_day_id', 'route_stop_id',
        'invoice_id', 'attachment_path', 'comment',
        'status', 'paired_claim_id',
        'confirmed_by', 'confirmed_at', 'transaction_id', 'reject_reason',
    ];

    protected $casts = [
        'amount'       => 'decimal:2',
        'confirmed_at' => 'datetime',
    ];

    public static function sourceLabels(): array
    {
        return [
            self::SOURCE_CLIENT_TRANSFER => 'Клієнт: переказ',
            self::SOURCE_CLIENT_CASH     => 'Клієнт: готівка курʼєру',
            self::SOURCE_COURIER_CASH    => 'Курʼєр: готівка у звіті',
            self::SOURCE_INVOICE_SENT    => 'Виставлено рахунок',
        ];
    }

    public static function statusLabels(): array
    {
        return [
            self::STATUS_PENDING    => 'Чекає',
            self::STATUS_CONFIRMED  => 'Підтверджено',
            self::STATUS_REJECTED   => 'Відхилено',
            self::STATUS_SUPERSEDED => 'Замінено',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function orderDay(): BelongsTo
    {
        return $this->belongsTo(OrderDay::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    public function pairedClaim(): BelongsTo
    {
        return $this->belongsTo(self::class, 'paired_claim_id');
    }

    public function confirmer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isCash(): bool
    {
        return in_array($this->source, self::CASH_SOURCES, true);
    }

    /**
     * Клієнт каже одну суму, курʼєр — іншу. Кейс «9400 проти 9300» з чатів:
     * менеджер має бачити це червоним ще до того, як підтвердить.
     */
    public function hasDiscrepancy(): bool
    {
        $pair = $this->relationLoaded('pairedClaim') ? $this->pairedClaim : $this->pairedClaim()->first();

        return $pair !== null
            && abs((float) $pair->amount - (float) $this->amount) > self::MONEY_EPSILON;
    }

    /** Заява рахунку — лише очікування, грошей за нею ще немає. */
    public function isExpectationOnly(): bool
    {
        return $this->source === self::SOURCE_INVOICE_SENT;
    }
}
