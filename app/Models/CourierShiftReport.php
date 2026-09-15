<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Звіт зміни курʼєра: шаблон пішов → курʼєр дописав → чернетка на перевірці →
 * адмін підтвердив (accepted) або відхилив (rejected).
 *
 * Поки звіт — чернетка, пробіг не записано і баланс курʼєра не рухався.
 *
 * @see \App\Services\Couriers\CourierReportService
 */
class CourierShiftReport extends Model
{
    public const STATUS_SENT       = 'sent';
    public const STATUS_INCOMPLETE = 'incomplete';
    public const STATUS_ACCEPTED   = 'accepted';
    public const STATUS_DRAFT      = 'draft';     // повний, чекає перевірки адміном
    public const STATUS_REJECTED   = 'rejected';

    public const SOURCE_BOT   = 'bot';
    public const SOURCE_INBOX = 'inbox';

    protected $fillable = [
        'employee_id', 'date', 'shift_slot', 'delivery_route_id',
        'tg_chat_id', 'template_message_id', 'template_sent_at', 'reminded_at',
        'expected', 'raw_text', 'parsed', 'photos', 'problems',
        'status', 'accepted_at',
        'source', 'inbox_conversation_id', 'anomalies', 'ai_comment',
        'reviewed_by', 'reviewed_at', 'reject_reason',
    ];

    protected $casts = [
        'expected'         => 'array',
        'parsed'           => 'array',
        'photos'           => 'array',
        'problems'         => 'array',
        'template_sent_at' => 'datetime',
        'reminded_at'      => 'datetime',
        'accepted_at'      => 'datetime',
        'anomalies'        => 'array',
        'reviewed_at'      => 'datetime',
    ];

    public static function statusLabels(): array
    {
        return [
            self::STATUS_SENT       => 'Шаблон надіслано',
            self::STATUS_INCOMPLETE => 'Неповний',
            self::STATUS_DRAFT      => 'На перевірці',
            self::STATUS_ACCEPTED   => 'Підтверджено',
            self::STATUS_REJECTED   => 'Відхилено',
        ];
    }

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

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    /** Найгірша позначка: red / yellow / info / null. */
    public function worstSeverity(): ?string
    {
        $levels = collect($this->anomalies ?? [])->pluck('severity');

        foreach (['red', 'yellow', 'info'] as $level) {
            if ($levels->contains($level)) {
                return $level;
            }
        }

        return null;
    }

    /**
     * Пробіг зміни для показу: сирий у одиниці одометра і в км.
     *
     * @return array{start: ?int, end: ?int, raw: ?int, unit: string, km: ?int, plan: float, stops: int}
     */
    public function mileageSummary(): array
    {
        $parsed = $this->parsed ?? [];
        $start  = $parsed['start_km'] ?? ($this->expected['start_km'] ?? null);
        $end    = $parsed['end_km'] ?? null;
        $unit   = $this->employee?->mileage_unit === 'mi' ? 'mi' : 'km';
        $raw    = ($start !== null && $end !== null) ? (int) $end - (int) $start : null;
        $km     = $raw === null ? null : ($unit === 'mi' ? (int) round($raw * CourierMileageLog::MI_TO_KM) : $raw);

        return [
            'start' => $start !== null ? (int) $start : null,
            'end'   => $end !== null ? (int) $end : null,
            'raw'   => $raw,
            'unit'  => $unit,
            'km'    => $km,
            'plan'  => (float) ($this->expected['distance'] ?? 0),
            'stops' => (int) ($this->expected['stops'] ?? 0),
        ];
    }

    public function dateString(): string
    {
        return \Carbon\Carbon::parse($this->getRawOriginal('date') ?? $this->date)->format('Y-m-d');
    }
}
