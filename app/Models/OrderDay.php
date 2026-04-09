<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class OrderDay extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_id', 'date', 'is_completed',
        'address', 'address_entrance', 'address_apartment', 'address_floor',
        'delivery_comment', 'delivery_time',
        'discount_type', 'discount_value', 'discount_amount',
        'ant_route_num', 'ant_route_pos', 'ant_driver', 'ant_delivery_group',
    ];

    protected $casts = [
        'date'            => 'date',
        'is_completed'    => 'boolean',
        'discount_value'  => 'decimal:2',
        'discount_amount' => 'decimal:2',
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
}