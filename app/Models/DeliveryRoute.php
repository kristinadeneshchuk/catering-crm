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
     * Зміна маршруту з сирого поля ANT `RouteTime_B` ('d.m.Y H:i').
     *
     * Потрібна ще до того, як маршрут потрапив у базу: за нею вирішуємо, чиї
     * саме рядки має право чистити свіжий імпорт з ANT.
     */
    public static function shiftFromRouteTime(?string $routeTimeB): ?string
    {
        if (! preg_match('/\s(\d{1,2}):/', (string) $routeTimeB, $m)) {
            return null;
        }

        return (int) $m[1] >= self::EVENING_HOUR ? 'evening' : 'morning';
    }

    /** Зміна маршруту рядком: 'morning' | 'evening' | null. */
    public function realShift(): ?string
    {
        $evening = $this->isEvening();

        return $evening === null ? null : ($evening ? 'evening' : 'morning');
    }

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
        if (!$this->date || (!$this->ant_route_id && !$this->ant_route_num)) {
            return 0;
        }

        $routeDate = \Carbon\Carbon::parse($this->date)->startOfDay();

        $query = OrderDay::query()->where('extra_delivery_fee', '>', 0);

        // Основний ключ — стабільний ant_route_id: Route_Num в ANT
        // перенумеровується при кожній перебудові маршрутів, і зв'язка
        // «номер + водій» розсипалась, щойно логіст перегравав розклад —
        // доплата за дальню доставку тоді не діставалась нікому.
        //
        // Легасі-гілка (num + driver) лишається для днів, записаних до появи
        // ant_route_id. День зі стабільним id матчиться ТІЛЬКИ по ньому,
        // інакше при перебудові стара пара num+driver могла б зачепити чужий
        // маршрут і порахувати доплату двічі.
        $legacyNum = function ($q) {
            $q->whereNull('ant_route_id')
              ->where('ant_route_num', $this->ant_route_num);
        };

        if ($this->ant_route_id && $this->ant_route_num) {
            $query->where(function ($q) use ($legacyNum) {
                $q->where('ant_route_id', (string) $this->ant_route_id)
                  ->orWhere($legacyNum);
            });
        } elseif ($this->ant_route_id) {
            $query->where('ant_route_id', (string) $this->ant_route_id);
        } else {
            $query->where('ant_route_num', $this->ant_route_num);
        }

        // Номер маршруту НЕ унікальний у межах дня (ранковий і вечірній прогони
        // обидва нумеруються з 1), тому легасі-рядки додатково звужуємо по
        // водію: OrderDay.ant_driver і DeliveryRoute.driver_name — одне поле
        // ANT `Driver`. Порівнюємо в PHP: SQL-ний LOWER() не знижує кирилицю
        // на sqlite, а рядків тут одиниці. NULL-водій — фолбек для зовсім
        // старих рядків без ant_driver.
        $driver = mb_strtolower(trim((string) $this->driver_name));

        return (float) $query
            ->with('order')
            ->get()
            ->filter(function ($d) use ($driver) {
                if ($this->ant_route_id && (string) $d->ant_route_id === (string) $this->ant_route_id) {
                    return true; // стабільний ключ — водія звіряти не треба
                }

                return $driver === ''
                    || $d->ant_driver === null
                    || mb_strtolower(trim((string) $d->ant_driver)) === $driver;
            })
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
