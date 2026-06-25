<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\OrderDay;
use App\Models\Setting;

class DeliveryRoute extends Model
{
    protected $fillable = [
        'date', 'shift',
        'ant_route_id', 'ant_route_num',
        'driver_name', 'employee_id', 'auto_name', 'model_auto', 'registration_number',
        'count_comps', 'distance_calc', 'distance_fact', 'fuel_city',
        'route_time_b', 'route_time_e',
        'ant_cost_route', 'calculated_cost',
    ];

    public function employee(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    protected $casts = [
        'date'          => 'date',
        'ant_cost_route'  => 'decimal:2',
        'calculated_cost' => 'decimal:2',
    ];

    /**
     * Розраховує вартість маршруту по ОСОБИСТІЙ ставці кур'єра.
     * База: employee.base_rate до base_stops точок.
     * Доплата: extra_per_stop за кожну точку понад base_stops.
     * Якщо кур'єра не призначено або у нього base_rate=0 — повертаємо 0
     * (треба задати ставку в картці кур'єра).
     */
    public static function calculateCourierCost(int $stops, ?Employee $courier = null): float
    {
        $baseRate     = (float) ($courier?->base_rate ?? 0);
        $baseStops    = (int)   (Setting::where('key', 'courier_base_stops')->value('value') ?: 12);
        $extraPerStop = (float) (Setting::where('key', 'courier_extra_per_stop')->value('value') ?: 50);

        if ($stops <= $baseStops) {
            return $baseRate;
        }

        return $baseRate + ($stops - $baseStops) * $extraPerStop;
    }

    /**
     * Сума доплат «дальня доставка» по всіх OrderDay цього маршруту (та сама дата + ant_route_num).
     */
    public function extraDeliveryFee(): float
    {
        if (!$this->ant_route_num || !$this->date) {
            return 0;
        }

        return (float) OrderDay::query()
            ->where('ant_route_num', $this->ant_route_num)
            ->whereDate('date', $this->date)
            ->sum('extra_delivery_fee');
    }

    /**
     * Повна вартість маршруту для кур'єра = базова ставка по точках + сума доплат за дальні доставки.
     */
    public function recalcCost(): float
    {
        return static::calculateCourierCost((int) $this->count_comps, $this->employee)
             + $this->extraDeliveryFee();
    }

    /**
     * Відстань факт (з fallback на план).
     */
    public function getDistanceAttribute(): ?float
    {
        return $this->distance_fact ?? $this->distance_calc;
    }

    /**
     * Тривалість маршруту у хвилинах.
     */
    public function getDurationMinutesAttribute(): ?int
    {
        if (!$this->route_time_b || !$this->route_time_e) return null;

        try {
            $start = \Carbon\Carbon::createFromFormat('d.m.Y H:i', $this->route_time_b);
            $end   = \Carbon\Carbon::createFromFormat('d.m.Y H:i', $this->route_time_e);
            return (int) $start->diffInMinutes($end);
        } catch (\Exception $e) {
            return null;
        }
    }
}
