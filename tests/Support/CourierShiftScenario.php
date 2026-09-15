<?php

namespace Tests\Support;

use App\Models\CourierMileageLog;
use App\Models\Employee;
use App\Models\EmployeeShift;
use App\Models\Order;
use App\Services\Couriers\CourierReportService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

/**
 * День курʼєра для тестів: маршрут, зміна, пробіг, готівкове замовлення, звіт.
 * Спільне для тестів виплат і перевірки звітів.
 */
trait CourierShiftScenario
{
    use BuildsCourierTestSchema;

    protected string $date = '2026-09-12';

    protected Employee $courier;

    protected function setUpCourierWorld(): void
    {
        $this->travelTo(\Carbon\Carbon::parse($this->date.' 20:00'));

        config()->set('services.telegram.bot_token', 'test-bot');
        config()->set('services.telegram.owner_chat_id', '100');
        config()->set('services.telegram.manager_chat_id', '200');
        config()->set('services.telegram.webhook_secret', 'sec');
        config()->set('services.inbox.webhook_url', '');

        Queue::fake();
        Storage::fake('local');

        Http::fake([
            'api.telegram.org/*/getFile*' => Http::response(['ok' => true, 'result' => ['file_path' => 'photos/odo.jpg']]),
            'api.telegram.org/file/*'     => Http::response('jpeg-bytes'),
            'api.telegram.org/*'          => Http::response(['ok' => true, 'result' => ['message_id' => 42]]),
        ]);

        $this->buildCourierSchema();

        $this->setting('courier_base_stops', '12');
        $this->setting('courier_extra_per_stop', '50');
        $this->setting('amort_per_km', '1');

        $this->courier = $this->makeCourier();
    }

    protected function route(array $attrs = []): int
    {
        return DB::table('delivery_routes')->insertGetId(array_merge([
            'date' => $this->date, 'shift' => 'evening', 'ant_route_id' => 'r'.uniqid(),
            'ant_route_num' => 14, 'employee_id' => $this->courier->id,
            'count_comps' => 11, 'route_time_b' => '12.09.2026 17:30',
        ], $attrs));
    }

    protected function shift(float $rate, string $slot = 'full', bool $planned = false): EmployeeShift
    {
        return EmployeeShift::create([
            'employee_id' => $this->courier->id, 'date' => $this->date,
            'shift_slot' => $slot, 'rate' => $rate, 'is_planned' => $planned,
        ]);
    }

    protected function mileage(int $start, int $end, float $price, string $slot = 'full'): CourierMileageLog
    {
        return CourierMileageLog::create([
            'employee_id' => $this->courier->id, 'date' => $this->date, 'shift_slot' => $slot,
            'start_km' => $start, 'end_km' => $end, 'fuel_price_per_liter' => $price,
            'fuel_consumption' => 8, 'amort_per_km' => 1,
        ]);
    }

    protected function cashOrder(string $method = 'cash'): Order
    {
        $clientId = $this->makeClient(['name' => 'Ірина Коваль']);
        $catalog  = $this->seedCatalog(pricePerDay: 520);

        $order = Order::create([
            'client_id' => $clientId, 'project' => 'afood', 'tariff_id' => $catalog['tariff_id'],
            'calories' => 1600, 'duration' => 5, 'start_date' => $this->date, 'end_date' => '2026-09-16',
            'scale_factor' => 1.0, 'payment_method' => $method,
        ]);

        DB::table('route_stops')->insert([
            'date' => $this->date, 'shift' => 'evening', 'employee_id' => $this->courier->id,
            'ant_route_id' => 'r1', 'ant_route_num' => 14, 'position' => 1,
            'client_id' => $clientId, 'client_name' => 'Ірина Коваль', 'address' => 'Печерськ, вул. Липська 5',
            'order_id' => $order->id, 'source' => 'ant',
        ]);

        return $order;
    }

    protected function sendReport(array $photos = ['ph1', 'ph2'], ?string $received = '+'): string
    {
        $order = Order::latest('id')->first();

        return app(CourierReportService::class)->ingest($this->courier->fresh(), <<<TXT
            ЗВІТ ЗМІНИ · 12.09 · вечір · маршрут №14 (11 точок)
            Пробіг старт: 177500
            Пробіг фініш: 177640
            Пальне, грн/л: 58.9
            - #{$order->id} Ірина К. (Печерськ) чекаємо 2600 → отримав: {$received}
            TXT, $photos);
    }

    protected function prepareShift(): Order
    {
        $this->route();
        $this->shift(800, 'evening');
        $order = $this->cashOrder();
        app(CourierReportService::class)->sendTemplate($this->courier->fresh(), $this->date, 'evening');

        return $order;
    }

}
