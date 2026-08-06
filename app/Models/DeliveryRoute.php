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

    /** Поріг ранок/вечір — той самий, що в AntLogisticsService::pullRouteDetails(). */
    public const EVENING_HOUR = 14;

    /**
     * Чи вечірній це маршрут: спершу колонка shift, інакше — година старту.
     * null — визначити не вдалося (немає ні shift, ні розпізнаваного часу).
     */
    public function isEvening(): ?bool
    {
        if ($this->shift === 'morning') return false;
        if ($this->shift === 'evening') return true;

        // route_time_b приходить з ANT у форматі 'd.m.Y H:i'
        if (preg_match('/\s(\d{1,2}):/', (string) $this->route_time_b, $m)) {
            return (int) $m[1] >= self::EVENING_HOUR;
        }

        return null;
    }

    /**
     * Фільтр по зміні для СПОЖИВАЧІВ (сторінка Логістики, SMS-розсилка).
     *
     * Колонка shift зберігає не зміну маршруту, а значення фільтра на момент
     * завантаження з ANT: тягнеш «Всі» — усі рядки лягають із shift='all'.
     * Тому прямий where('shift', 'morning') давав нуль рядків, сторінка
     * показувала «маршрутів немає», а кнопка SMS гасла з «Маршрути ще не
     * побудовані» — хоча маршрути були.
     *
     * Розводимо зміни за реальним часом старту. Фільтруємо в PHP, а не в SQL:
     * route_time_b — рядок 'd.m.Y H:i', його розбір у SQL різний для MySQL і
     * sqlite (тести), а маршрутів на день одиниці.
     *
     * @param  \Illuminate\Support\Collection<int, static>  $routes
     * @return \Illuminate\Support\Collection<int, static>
     */
    public static function filterByShift(\Illuminate\Support\Collection $routes, ?string $shift): \Illuminate\Support\Collection
    {
        if (! in_array($shift, ['morning', 'evening'], true)) {
            return $routes;
        }

        $wantEvening = $shift === 'evening';

        // Маршрут із нерозпізнаваним часом (isEvening() === null) лишаємо в
        // обох змінах: краще показати зайвий, ніж загубити його зовсім.
        return $routes->filter(function (self $r) use ($wantEvening) {
            $isEvening = $r->isEvening();

            return $isEvening === null || $isEvening === $wantEvening;
        })->values();
    }

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
     * Сума доплат «дальня доставка» по всіх OrderDay цього маршруту.
     *
     * УВАГА: OrderDay.date — це дата ЇЖІ, а DeliveryRoute.date — дата ДОСТАВКИ.
     * Для вечірніх замовлень і delivery_date_override вони різняться, тому
     * матчимо за реальною датою доставки через resolveDeliveryDate().
     */
    public function extraDeliveryFee(): float
    {
        if (!$this->ant_route_num || !$this->date) {
            return 0;
        }

        $routeDate = \Carbon\Carbon::parse($this->date)->startOfDay();

        $query = OrderDay::query()
            ->where('ant_route_num', $this->ant_route_num)
            ->where('extra_delivery_fee', '>', 0);

        // Номер маршруту НЕ унікальний у межах дня: коли є ранковий і вечірній
        // прогони, обидва нумеруються з 1. Без цього звуження доплата за дальню
        // доставку задвоювалась — падала одразу на двох курʼєрів з однаковим
        // номером маршруту. Матчимо ще й по водію: OrderDay.ant_driver і
        // DeliveryRoute.driver_name беруться з одного поля ANT `Driver`.
        // NULL-водія лишаємо як легасі-fallback (старі рядки без ant_driver).
        $driver = trim((string) $this->driver_name);
        if ($driver !== '') {
            $query->where(function ($q) use ($driver) {
                $q->whereNull('ant_driver')
                  ->orWhereRaw('LOWER(TRIM(ant_driver)) = ?', [mb_strtolower($driver)]);
            });
        }

        return (float) $query
            ->with('order')
            ->get()
            ->filter(fn ($d) => $d->resolveDeliveryDate()->startOfDay()->equalTo($routeDate))
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
