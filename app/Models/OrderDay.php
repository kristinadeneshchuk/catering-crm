<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;

class OrderDay extends Model
{
    use HasFactory, LogsActivity;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly([
                'order_id', 'date', 'is_completed',
                'address', 'address_entrance', 'address_apartment', 'address_floor',
                'delivery_comment', 'delivery_time', 'delivery_date_override',
                'discount_type', 'discount_value', 'discount_amount',
                'extra_delivery_fee',
                'fake_kcal', 'fake_prot', 'fake_fat', 'fake_carb',
                'ant_route_num', 'ant_route_pos', 'ant_driver', 'ant_delivery_group',
            ])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('order_day');
    }

    protected $fillable = [
        'order_id', 'date', 'is_completed',
        'address', 'address_entrance', 'address_apartment', 'address_floor',
        'delivery_comment', 'delivery_time', 'delivery_date_override',
        'discount_type', 'discount_value', 'discount_amount',
        'extra_delivery_fee',
        'fake_kcal', 'fake_prot', 'fake_fat', 'fake_carb',
        'ant_route_num', 'ant_route_pos', 'ant_driver', 'ant_delivery_group',
    ];

    protected $casts = [
        'date'                   => 'date',
        'delivery_date_override' => 'date',
        'is_completed'           => 'boolean',
        'discount_value'         => 'decimal:2',
        'discount_amount'        => 'decimal:2',
        'extra_delivery_fee'     => 'decimal:2',
        'fake_kcal'              => 'integer',
        'fake_prot'              => 'decimal:1',
        'fake_fat'               => 'decimal:1',
        'fake_carb'              => 'decimal:1',
    ];

    protected static function booted(): void
    {
        static::saving(function ($day) {
            $day->discount_amount = $day->calculateDiscountAmount();
        });

        static::saved(function ($day) {
            if ($day->wasChanged('discount_amount')) {
                $day->order?->syncFinalPrice();
            }
        });

        static::deleted(function ($day) {
            // Якщо день мав знижку — перерахувати final_price замовлення
            if ((float) $day->discount_amount > 0) {
                $day->order?->syncFinalPrice();
            }
        });
    }

    public function calculateDiscountAmount(): float
    {
        if (!$this->discount_type || !$this->discount_value || (float) $this->discount_value <= 0) {
            return 0;
        }

        $order = $this->order ?? Order::find($this->order_id);
        if (!$order) return 0;

        $pricePerDay = $order->duration > 0
            ? (float) $order->total_price / $order->duration
            : 0;

        return match ($this->discount_type) {
            'percent' => round($pricePerDay * (float) $this->discount_value / 100, 2),
            'fixed'   => min((float) $this->discount_value, $pricePerDay),
            default   => 0,
        };
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * Фактична дата доставки цього дня.
     * Якщо є override — повертаємо його. Інакше — глобальне правило (закриті слоти).
     */
    public function resolveDeliveryDate(): \Carbon\Carbon
    {
        if ($this->delivery_date_override) {
            return \Carbon\Carbon::parse($this->delivery_date_override);
        }

        $isEvening = \App\Services\ScheduleService::isEvening($this->order?->schedule_type);

        return \App\Services\ScheduleService::computeDeliveryDate(
            \Carbon\Carbon::parse($this->date),
            $isEvening,
        );
    }
}