<?php

namespace App\Services;

use App\Models\DeliveryRoute;
use App\Models\Employee;
use App\Models\OrderDay;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Маршрути з RouteLogix (наш планувальник) замість ANT — для однієї зміни.
 *
 * Логіст будує зміну в RouteLogix і тисне «Наліпки: передати в CRM». Тоді тут:
 *  1) order_days отримують номер маршруту, позицію й водія — те саме, що
 *     друкується на наліпках (/print/mini-manifest), з route_source='routelogix';
 *  2) delivery_routes — виїзд на кожен маршрут, з курʼєром і кількістю адрес,
 *     щоб ставка, доплата за точки понад 12 і дальні рахувались як з ANT.
 *
 * Синк з ANT ці рядки не перезаписує і маршрутів ANT на таку зміну не створює.
 * Поки config('services.logist.labels') вимкнено, працює лише перегляд.
 */
class RouteLogixService
{
    public const SOURCE = 'routelogix';

    public function enabled(): bool
    {
        return (bool) config('services.logist.labels');
    }

    /**
     * Сирий план з RouteLogix.
     *
     * @return array{plan_id:mixed, date:string, shift:string, published_at:?string, edited_after:mixed, stops:array<int, array>}
     */
    public function fetch(string $date, string $shift, bool $preview = false): array
    {
        $this->assertShift($shift);

        $token = (string) config('services.logist.token');
        if ($token === '') {
            throw new RuntimeException('Не задано LOGIST_CRM_TOKEN у .env CRM.');
        }

        $response = Http::withToken($token)
            ->acceptJson()
            ->timeout(20)
            ->get(rtrim((string) config('services.logist.url'), '/') . '/api/crm/v1/routes', array_filter([
                'date'    => Carbon::parse($date)->format('Y-m-d'),
                'shift'   => $shift,
                'preview' => $preview ? 1 : null,
            ]));

        if ($response->status() === 404) {
            throw new RuntimeException('Логіст ще не передав цю зміну в CRM («Наліпки: передати в CRM»).');
        }

        if (! $response->successful()) {
            throw new RuntimeException('RouteLogix відповів ' . $response->status() . '.');
        }

        $plan = $response->json();
        if (! is_array($plan) || ! is_array($plan['stops'] ?? null)) {
            throw new RuntimeException('RouteLogix повернув відповідь без stops.');
        }

        return $plan;
    }

    /**
     * Забрати зміну з RouteLogix і записати в CRM.
     *
     * $preview — лише показати, що буде записано: нічого не пишемо, і прапорець
     * для цього не потрібен.
     *
     * @return array{written:bool, routes:int, stops:int, days:int, cleared:int, ant_removed:int, unknown_days:array<int,int>, unmatched_couriers:array<int,string>}
     */
    public function import(string $date, string $shift, bool $preview = false): array
    {
        $this->assertShift($shift);
        $date = Carbon::parse($date)->format('Y-m-d');

        $plan = $this->fetch($date, $shift, $preview);

        // Пишемо лише в дні, які CRM сама везе цією датою і зміною. Чужий id
        // (інша дата, скасоване замовлення) пропускаємо й показуємо.
        $ours = app(AntLogisticsService::class)
            ->collectOrderDaysForDelivery($date, $shift)['days']
            ->keyBy('id');

        $pool   = $this->courierPool();
        $routes = [];
        $unknown = [];
        $unmatched = [];

        foreach ($plan['stops'] as $stop) {
            $num = (int) ($stop['route_num'] ?? 0);
            if (! $num) {
                continue;
            }

            $dayIds = collect($stop['order_day_ids'] ?? [])->map(fn ($id) => (int) $id);
            $known  = $dayIds->filter(fn ($id) => $ours->has($id));
            array_push($unknown, ...$dayIds->diff($known)->all());

            $routes[$num] ??= [
                'driver'  => $stop['driver'] ?? null,
                'courier' => null,
                'stops'   => [],
                'etas'    => [],
            ];

            $routes[$num]['stops'][] = [
                'pos'     => (int) ($stop['route_pos'] ?? 0) ?: null,
                'day_ids' => $known->values()->all(),
            ];

            if (! empty($stop['eta'])) {
                $routes[$num]['etas'][] = (string) $stop['eta'];
            }

            if (! $routes[$num]['courier']) {
                $routes[$num]['courier'] = $this->resolveCourier($stop, $pool);
            }
        }

        foreach ($routes as $num => $route) {
            if (! $route['courier']) {
                $unmatched[$num] = (string) ($route['driver'] ?? '—');
            }
        }

        $result = [
            'written'            => false,
            'routes'             => count($routes),
            'stops'              => array_sum(array_map(fn ($r) => count($r['stops']), $routes)),
            'days'               => array_sum(array_map(
                fn ($r) => array_sum(array_map(fn ($s) => count($s['day_ids']), $r['stops'])),
                $routes,
            )),
            'cleared'            => 0,
            'ant_removed'        => 0,
            'unknown_days'       => array_values(array_unique($unknown)),
            'unmatched_couriers' => $unmatched,
        ];

        if ($preview || ! $this->enabled()) {
            return $result;
        }

        DB::transaction(function () use ($date, $shift, $routes, $ours, &$result) {
            $result = array_merge($result, $this->write($date, $shift, $routes, $ours));
        });

        $result['written'] = true;

        Log::info('[RouteLogix] Зміну записано', ['date' => $date, 'shift' => $shift, 'plan_id' => $plan['plan_id'] ?? null] + $result);

        return $result;
    }

    /**
     * @param  array<int, array{driver:?string, courier:?Employee, stops:array, etas:array}>  $routes
     * @param  Collection<int, OrderDay>  $ours
     * @return array{cleared:int, ant_removed:int}
     */
    private function write(string $date, string $shift, array $routes, Collection $ours): array
    {
        $written = [];

        foreach ($routes as $num => $route) {
            $key = $this->routeKey($date, $shift, $num);

            foreach ($route['stops'] as $stop) {
                if (! $stop['day_ids']) {
                    continue;
                }

                OrderDay::whereIn('id', $stop['day_ids'])->update([
                    'ant_route_num'      => $num,
                    'ant_route_id'       => $key,
                    'ant_route_pos'      => $stop['pos'],
                    'ant_driver'         => $route['driver'],
                    'ant_delivery_group' => null,
                    'route_source'       => self::SOURCE,
                ]);

                array_push($written, ...$stop['day_ids']);
            }
        }

        // Дні зміни, які логіст зняв з маршрутів після попередньої передачі,
        // не мають лишатись зі старим номером на наліпці.
        $cleared = OrderDay::whereIn('id', $ours->keys()->diff($written)->values()->all())
            ->where('route_source', self::SOURCE)
            ->update([
                'ant_route_num' => null, 'ant_route_id' => null, 'ant_route_pos' => null,
                'ant_driver' => null, 'route_source' => null,
            ]);

        // Виїзди. Через модель — DeliveryRouteObserver переоцінить зміну курʼєра в Табелі.
        $keys = [];

        foreach ($routes as $num => $route) {
            $key    = $this->routeKey($date, $shift, $num);
            $keys[] = $key;

            $model = DeliveryRoute::updateOrCreate(
                ['date' => $date, 'ant_route_id' => $key],
                [
                    'shift'         => $shift,
                    'source'        => self::SOURCE,
                    'ant_route_num' => $num,
                    'driver_name'   => $route['driver'],
                    'employee_id'   => $route['courier']?->id,
                    'count_comps'   => count($route['stops']),
                    'route_time_b'  => $this->routeTime($date, $shift, $route['etas'], 'min'),
                    'route_time_e'  => $this->routeTime($date, $shift, $route['etas'], 'max'),
                ]
            );

            $cost = $model->recalcCost();
            if (abs((float) $model->calculated_cost - $cost) >= 0.005) {
                $model->update(['calculated_cost' => $cost]);
            }
        }

        $own = DeliveryRoute::whereDate('date', $date)->where('shift', $shift)->where('source', self::SOURCE);

        (clone $own)->whereNotIn('ant_route_id', $keys ?: ['-'])->get()->each->delete();

        // Маршрути ANT цієї зміни: виїзд тепер рахуємо з RouteLogix, а два
        // маршрути на одну зміну дали б курʼєру подвійну ставку.
        $ant = DeliveryRoute::filterByShift(
            DeliveryRoute::whereDate('date', $date)
                ->where(fn ($q) => $q->whereNull('source')->orWhere('source', '!=', self::SOURCE))
                ->get(),
            $shift,
        )->filter(fn (DeliveryRoute $r) => $r->realShift() === $shift);

        $ant->each->delete();

        return ['cleared' => $cleared, 'ant_removed' => $ant->count()];
    }

    /**
     * Курʼєр маршруту: employee_id від RouteLogix (couriers.crm_employee_id),
     * інакше — той самий матч за іменем водія, що й для ANT.
     */
    private function resolveCourier(array $stop, Collection $pool): ?Employee
    {
        if (! empty($stop['employee_id'])) {
            $byId = $pool->firstWhere('id', (int) $stop['employee_id'])
                ?? Employee::find((int) $stop['employee_id']);

            if ($byId) {
                return $byId;
            }
        }

        $driver = trim((string) ($stop['driver'] ?? ''));

        return $driver === '' ? null : AntLogisticsService::matchDriverToEmployee($driver, $pool);
    }

    private function courierPool(): Collection
    {
        return Employee::whereNotNull('ant_driver_name')
            ->orWhere(fn ($q) => $q->where('position', 'courier')->whereNull('archived_at'))
            ->get();
    }

    /**
     * Ключ маршруту на місці Route_Id з ANT: зміна + номер, від'ємний.
     *
     * delivery_routes.ant_route_id — int, а Route_Id у ANT додатні, тож
     * від'ємні числа з ними не перетнуться. Дату ключ не несе: і маршрут,
     * і доплата за дальню доставку шукаються в межах дати.
     */
    private function routeKey(string $date, string $shift, int $num): string
    {
        return (string) -(($shift === 'evening' ? 2000 : 1000) + $num);
    }

    /**
     * Перший/останній ETA маршруту у форматі ANT 'd.m.Y H:i'.
     *
     * ETA приходить як «HH:MM»; вечірня зміна буває після півночі («00:20»),
     * і такий час належить уже наступній добі.
     */
    private function routeTime(string $date, string $shift, array $etas, string $edge): ?string
    {
        $times = collect($etas)
            ->map(function ($eta) use ($date, $shift) {
                try {
                    if (! preg_match('/^\d{1,2}:\d{2}$/', $eta)) {
                        return Carbon::parse($eta);
                    }

                    $time = Carbon::parse("{$date} {$eta}");

                    return $shift === 'evening' && $time->hour < 12 ? $time->addDay() : $time;
                } catch (\Throwable) {
                    return null;
                }
            })
            ->filter();

        $time = $edge === 'min' ? $times->min() : $times->max();

        return $time?->format('d.m.Y H:i');
    }

    private function assertShift(string $shift): void
    {
        if (! in_array($shift, ['morning', 'evening'], true)) {
            throw new RuntimeException('Зміна має бути morning або evening.');
        }
    }
}
