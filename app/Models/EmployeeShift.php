<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmployeeShift extends Model
{
    /**
     * shift_slot — для курʼєрів:
     *   'morning' / 'evening' — один виїзд (одинарна ставка),
     *   'full'                — і ранок, і вечір, тобто два виїзди (подвійна).
     *
     * Для кухні та офісу слоти не мають сенсу: там день не ділиться на ранок і
     * вечір, тож завжди 'full', а пів зміни позначається is_half.
     */
    public const SLOT_FULL = 'full';
    public const SLOT_MORNING = 'morning';
    public const SLOT_EVENING = 'evening';

    protected $fillable = ['employee_id', 'date', 'shift_slot', 'rate', 'is_duty', 'is_half', 'is_planned'];

    // Не кастимо 'date' — щоб keyBy('date') / порівняння залишали формат 'Y-m-d'
    // (Carbon з cast='date' дає ключ '2026-05-25 00:00:00', що ламає пошук по даті).
    protected $casts = [
        'rate'    => 'decimal:2',
        'is_duty'    => 'boolean',
        'is_half'    => 'boolean',
        'is_planned' => 'boolean',
    ];

    /** Один виїзд: ранок або вечір. Для курʼєра це одинарна ставка. */
    public function isSingleTrip(): bool
    {
        return in_array($this->shift_slot, [self::SLOT_MORNING, self::SLOT_EVENING], true);
    }

    public function slotLabel(): string
    {
        return match ($this->shift_slot) {
            self::SLOT_MORNING => 'Ранок',
            self::SLOT_EVENING => 'Вечір',
            default            => 'Ранок + вечір',
        };
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }
}
