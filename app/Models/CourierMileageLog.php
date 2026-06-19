<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CourierMileageLog extends Model
{
    protected $fillable = ['employee_id', 'date', 'start_km', 'end_km', 'fuel_uah', 'amort_per_km'];

    protected $casts = [
        'date'         => 'date',
        'fuel_uah'     => 'decimal:2',
        'amort_per_km' => 'decimal:2',
    ];

    public function employee(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function getKmAttribute(): int
    {
        if ($this->start_km === null || $this->end_km === null) return 0;
        return max(0, (int) $this->end_km - (int) $this->start_km);
    }

    /**
     * Поточна ставка амортизації з налаштувань — використовується ПРИ СТВОРЕННІ
     * нового логу (далі знімок зберігається в колонку amort_per_km).
     */
    public static function currentAmortPerKm(): float
    {
        return (float) (Setting::where('key', 'amort_per_km')->value('value') ?? 1);
    }

    public function getAmortizationAttribute(): float
    {
        // Беремо знімок із власної колонки, а не з live-settings — щоб після зміни
        // налаштування історичні нарахування лишались консистентними з balance.
        $rate = $this->amort_per_km !== null ? (float) $this->amort_per_km : self::currentAmortPerKm();
        return round($this->km * $rate, 2);
    }

    public function getCompensationAttribute(): float
    {
        return round((float) $this->fuel_uah + $this->amortization, 2);
    }
}
