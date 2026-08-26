<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Точка маршруту — знімок однієї доставки на момент виїзду.
 *
 * Читається з CRM, пишеться з вивантаження ANT. У зворотну синхронізацію з ANT
 * не входить: ANT — джерело живого стану, це — архів.
 *
 * @see database/migrations/2026_08_26_170000_create_route_stops_table.php
 */
class RouteStop extends Model
{
    public const SOURCE_ANT      = 'ant';
    public const SOURCE_BACKFILL = 'backfill';

    protected $fillable = [
        'date', 'shift',
        'delivery_route_id', 'ant_route_id', 'ant_route_num', 'position',
        'employee_id', 'driver_name', 'courier_name', 'courier_phone', 'car_number',
        'client_id', 'client_name', 'client_phone', 'address',
        'order_id', 'order_day_id', 'source',
    ];

    // date не кастимо в Carbon свідомо — так само, як в EmployeeShift:
    // groupBy('date') має лишати ключ у форматі 'Y-m-d'.
    protected $casts = [
        'ant_route_num' => 'integer',
        'position'      => 'integer',
    ];

    public function employee(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function client(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function order(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function deliveryRoute(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(DeliveryRoute::class);
    }

    /**
     * Точки на дату доставки.
     *
     * $shift = 'all' повертає обидві зміни — саме те, чого не вміє ANT і заради
     * чого існує ця таблиця.
     */
    public function scopeForDelivery($query, string $date, string $shift = 'all')
    {
        $query->whereDate('date', $date);

        if (in_array($shift, ['morning', 'evening'], true)) {
            // Рядки без визначеної зміни лишаємо в обох: краще показати зайву
            // точку, ніж загубити її зовсім.
            $query->where(fn ($q) => $q->where('shift', $shift)->orWhereNull('shift'));
        }

        return $query;
    }

    /** Чи вистачає даних, щоб повідомити клієнта. */
    public function isComplete(): bool
    {
        return $this->client_phone && $this->courier_name && $this->courier_phone && $this->car_number;
    }
}
