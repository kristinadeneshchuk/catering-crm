<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CourierMileageLog extends Model
{
    public const SLOT_FULL = 'full';
    public const SLOT_MORNING = 'morning';
    public const SLOT_EVENING = 'evening';

    // Коефіцієнт переводу миль у кілометри (за домовленістю з менеджером).
    public const MI_TO_KM = 1.6;

    protected $fillable = [
        'employee_id', 'date', 'shift_slot',
        'start_km', 'end_km',
        'fuel_price_per_liter', 'fuel_consumption', 'amort_per_km',
        'mileage_unit',
    ];

    protected $casts = [
        'date'                 => 'date',
        'fuel_price_per_liter' => 'decimal:2',
        'fuel_consumption'     => 'decimal:2',
        'amort_per_km'         => 'decimal:2',
    ];

    public function employee(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * Пробіг у КІЛОМЕТРАХ. Якщо одометр у милях — конвертуємо (× 1.6).
     * Всі подальші розрахунки (компенсація, амортизація, пальне) — у км.
     */
    public function getKmAttribute(): int
    {
        if ($this->start_km === null || $this->end_km === null) return 0;
        $diff = max(0, (int) $this->end_km - (int) $this->start_km);
        if (($this->mileage_unit ?? 'km') === 'mi') {
            return (int) round($diff * self::MI_TO_KM);
        }
        return $diff;
    }

    /** Сирий пробіг у одиниці одометра (mi або km) — для показу у формі. */
    public function getRawDiffAttribute(): int
    {
        if ($this->start_km === null || $this->end_km === null) return 0;
        return max(0, (int) $this->end_km - (int) $this->start_km);
    }

    /**
     * Поточна ставка амортизації — використовується ПРИ СТВОРЕННІ нового логу;
     * далі знімок зберігається в колонку amort_per_km.
     */
    public static function currentAmortPerKm(): float
    {
        return (float) (Setting::where('key', 'amort_per_km')->value('value') ?? 1);
    }

    /**
     * Витрачено літрів за день: пробіг × витрата машини / 100.
     */
    public function getLitersUsedAttribute(): float
    {
        $consumption = (float) ($this->fuel_consumption ?? 0);
        return round(($this->km * $consumption) / 100, 2);
    }

    /**
     * Вартість спаленого пального: літри × ціна літра.
     */
    public function getFuelCostAttribute(): float
    {
        return round($this->liters_used * (float) ($this->fuel_price_per_liter ?? 0), 2);
    }

    public function getAmortizationAttribute(): float
    {
        // Знімок, а не live-setting — щоб історичні нарахування лишались
        // консистентними з тим, що вже списано в balance.
        $rate = $this->amort_per_km !== null ? (float) $this->amort_per_km : self::currentAmortPerKm();
        return round($this->km * $rate, 2);
    }

    public function getCompensationAttribute(): float
    {
        return round($this->fuel_cost + $this->amortization, 2);
    }
}
