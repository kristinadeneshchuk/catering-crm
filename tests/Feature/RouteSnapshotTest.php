<?php

namespace Tests\Feature;

use App\Models\DeliveryRoute;
use App\Models\RouteStop;
use App\Services\AntLogisticsService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\BuildsSmsTestSchema;
use Tests\TestCase;

/**
 * Архів маршрутів і точок.
 *
 * В ANT на дату вміщується один комплект маршрутів. Логіст будує вечірні — і
 * видаляє ранкові. CRM бачила «ранкових більше нема» і зносила їх у себе разом
 * з історією виїздів і ранковою ставкою курʼєра: з 72 днів на проді обидві
 * зміни вціліли лише на 10.
 *
 * CRM тут не дзеркало ANT, а накопичувач.
 */
class RouteSnapshotTest extends TestCase
{
    use BuildsSmsTestSchema;

    protected string $deliveryDate = '2026-08-05';

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildSmsSchema();

        \App\Models\Setting::create(['key' => 'ant_base_url', 'value' => 'https://ant.test/api']);
    }

    /**
     * Відповідь ANT: список маршрутів. Точки не потрібні — pullRouteDetails
     * тягне лише шапки.
     */
    private function fakeAntRoutes(array $routes): void
    {
        Http::fake([
            '*auth*'       => Http::response(['Session_Ident' => 'sess-test']),
            '*Routes/get*' => Http::response($routes),
            '*'            => Http::response([]),
        ]);
    }

    private function antRoute(string $id, int $num, string $timeB, string $car): array
    {
        return [
            'Route_Id'            => $id,
            'Route_Num'           => $num,
            'Driver'              => 'Іванов І.І.',
            'RouteTime_B'         => $timeB,
            'RouteTime_E'         => $timeB,
            'Registration_Number' => $car,
            'Count_Comps'         => 5,
            'Cost_Route'          => 0,
        ];
    }

    // --- головне: чужа зміна недоторкана ------------------------------------

    public function test_pulling_the_evening_does_not_delete_the_morning(): void
    {
        // Ранкові маршрути вже в CRM.
        $this->makeRoute([
            'ant_route_id' => 'm1', 'ant_route_num' => 1,
            'route_time_b' => '05.08.2026 09:00', 'registration_number' => 'AA1111AA',
        ]);

        // Логіст видалив їх в ANT і побудував вечірні — ANT віддає тільки вечір.
        $this->fakeAntRoutes([$this->antRoute('e1', 1, '05.08.2026 18:00', 'BB2222BB')]);

        app(AntLogisticsService::class)->pullRouteDetails($this->deliveryDate, 'all');

        $this->assertNotNull(
            DeliveryRoute::where('ant_route_id', 'm1')->first(),
            'ранковий маршрут має вціліти — ANT його просто не показав',
        );
        $this->assertNotNull(DeliveryRoute::where('ant_route_id', 'e1')->first());
        $this->assertSame(2, DeliveryRoute::count());
    }

    public function test_a_rebuilt_route_of_the_same_shift_is_still_replaced(): void
    {
        // А от у межах СВОЄЇ зміни чистка має працювати як раніше: інакше
        // видалений при перебудові маршрут лишався б назавжди — зайві точки в
        // шапці і зайва ставка в ЗП курʼєра.
        $this->makeRoute([
            'ant_route_id' => 'e-old', 'ant_route_num' => 1,
            'route_time_b' => '05.08.2026 18:00',
        ]);

        $this->fakeAntRoutes([$this->antRoute('e-new', 1, '05.08.2026 18:30', 'BB2222BB')]);

        app(AntLogisticsService::class)->pullRouteDetails($this->deliveryDate, 'all');

        $this->assertNull(DeliveryRoute::where('ant_route_id', 'e-old')->first());
        $this->assertNotNull(DeliveryRoute::where('ant_route_id', 'e-new')->first());
    }

    public function test_an_empty_ant_response_deletes_nothing(): void
    {
        $this->makeRoute(['ant_route_id' => 'm1', 'route_time_b' => '05.08.2026 09:00']);

        $this->fakeAntRoutes([]);

        app(AntLogisticsService::class)->pullRouteDetails($this->deliveryDate, 'all');

        $this->assertSame(1, DeliveryRoute::count(), 'порожня відповідь — не привід чистити базу');
    }

    public function test_a_route_with_unreadable_time_is_never_deleted(): void
    {
        // Зміну не визначили — краще зайвий рядок, ніж мовчки стерта історія.
        $this->makeRoute(['ant_route_id' => 'weird', 'route_time_b' => null]);

        $this->fakeAntRoutes([$this->antRoute('e1', 1, '05.08.2026 18:00', 'BB2222BB')]);

        app(AntLogisticsService::class)->pullRouteDetails($this->deliveryDate, 'all');

        $this->assertNotNull(DeliveryRoute::where('ant_route_id', 'weird')->first());
    }

    public function test_the_real_shift_is_stored_not_the_filter_value(): void
    {
        // Раніше в колонку осідало значення фільтра ('all'), і зміну доводилось
        // щоразу вгадувати по route_time_b.
        $this->fakeAntRoutes([
            $this->antRoute('m1', 1, '05.08.2026 06:03', 'AA1111AA'),
            $this->antRoute('e1', 2, '05.08.2026 17:20', 'BB2222BB'),
        ]);

        app(AntLogisticsService::class)->pullRouteDetails($this->deliveryDate, 'all');

        $this->assertSame('morning', DeliveryRoute::where('ant_route_id', 'm1')->first()->shift);
        $this->assertSame('evening', DeliveryRoute::where('ant_route_id', 'e1')->first()->shift);
    }

    // --- архів точок --------------------------------------------------------

    public function test_a_stop_survives_the_order_leaving_active_status(): void
    {
        // Саме тут ламалось раніше: точка жила полем на order_days, а штатний
        // шлях бачить лише замовлення в статусах active/new. На проді так уже
        // 7195 точок з 7780.
        $this->makeRoute(['employee_id' => $this->makeCourier('Іванов І.І.')]);
        $ids = $this->makeOrderDay();

        DB::table('orders')->where('id', $ids['order_id'])->update(['status' => 'finished']);

        $stop = RouteStop::find($ids['stop_id']);

        $this->assertNotNull($stop);
        $this->assertSame('Іванов І.І.', $stop->courier_name);
        $this->assertSame('AA0000AA', $stop->car_number);
    }

    public function test_a_stop_survives_the_route_being_deleted(): void
    {
        $routeId = $this->makeRoute(['employee_id' => $this->makeCourier('Іванов І.І.')]);
        $ids     = $this->makeOrderDay();

        DB::table('delivery_routes')->where('id', $routeId)->delete();

        $stop = RouteStop::find($ids['stop_id']);

        $this->assertNotNull($stop, 'знімок не має падати разом з маршрутом');
        $this->assertSame('AA0000AA', $stop->car_number);
    }

    public function test_both_shifts_live_side_by_side_in_the_archive(): void
    {
        $courier = $this->makeCourier('Іванов І.І.');

        $morning = $this->makeRoute(['employee_id' => $courier, 'route_time_b' => '05.08.2026 09:00']);
        $evening = $this->makeRoute(['employee_id' => $courier, 'route_time_b' => '05.08.2026 18:00']);

        $this->makeOrderDay(['phone' => '0501111111'], [], [], ['route' => $morning]);
        $this->makeOrderDay(['phone' => '0502222222'], [], [], ['route' => $evening]);

        $this->assertSame(1, RouteStop::forDelivery($this->deliveryDate, 'morning')->count());
        $this->assertSame(1, RouteStop::forDelivery($this->deliveryDate, 'evening')->count());
        $this->assertSame(2, RouteStop::forDelivery($this->deliveryDate, 'all')->count());
    }
}
