<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Звіт зміни курʼєра: шаблон пішов → курʼєр дописав → прийнято.
 *
 * @see \App\Services\Couriers\CourierReportService
 */
class CourierShiftReport extends Model
{
    public const STATUS_SENT       = 'sent';
    public const STATUS_INCOMPLETE = 'incomplete';
    public const STATUS_ACCEPTED   = 'accepted';

    protected $fillable = [
        'employee_id', 'date', 'shift_slot', 'delivery_route_id',
        'tg_chat_id', 'template_message_id', 'template_sent_at', 'reminded_at',
        'expected', 'raw_text', 'parsed', 'photos', 'problems',
        'status', 'accepted_at',
    ];

    protected $casts = [
        'expected'         => 'array',
        'parsed'           => 'array',
        'photos'           => 'array',
        'problems'         => 'array',
        'template_sent_at' => 'datetime',
        'reminded_at'      => 'datetime',
        'accepted_at'      => 'datetime',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function deliveryRoute(): BelongsTo
    {
        return $this->belongsTo(DeliveryRoute::class);
    }

    public function isAccepted(): bool
    {
        return $this->status === self::STATUS_ACCEPTED;
    }

    public function dateString(): string
    {
        return \Carbon\Carbon::parse($this->getRawOriginal('date') ?? $this->date)->format('Y-m-d');
    }
}
