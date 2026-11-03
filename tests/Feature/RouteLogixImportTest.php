<?php

namespace Tests\Feature;

use App\Models\DeliveryRoute;
use App\Services\AntLogisticsService;
use App\Services\RouteLogixService;
use App\Services\ScheduleService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\BuildsCourierTestSchema;
use Tests\TestCase;

/**
 * Маршрути з RouteLogix замість ANT для однієї зміни: наліпки (order_days) і
 * виїзди (delivery_routes). Головне — за вимкненого прапорця нічого не пишемо,
 * і синк з ANT не затирає те, що дав RouteLogix.
 */
class RouteLogixImportTest extends TestCase
{
    use BuildsCourierTestSchema;

    private string $date = '2026-10-27';

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildCourierSchema();
        (require database_path('migrations/2026_11_03_100000_add_route_source_for_routelogix.php'))->up();
        $this->setting(ScheduleService::CLOSED_SLOTS_KEY, '[]');
        ScheduleService::clearClosedSlotsCache();

        config(['services.logist.url' => 'https://logist.test', 'services.logist.token' => 't0k']);
    }

    private function makeDay(): int
    {
        $client = $this->makeClient();
        $order  = DB::table('orders')->insertGetId([
            'client_id' => $client, 'status' => 'active', 'schedule_type' => 'every_day_morning',
        ]);

        return DB::table('order_days')->insertGetId(['order_id' => $order, 'date' => $this->date]);
    }

    private function fakePlan(array $stops): void
    {
        Http::fake(['logist.test/api/crm/v1/routes*' => Http::response([
            'plan_id' => 9, 'date' => $this->date, 'shift' => 'morning',
            'published_at' => '2026-10-26 20:00', 'edited_after' => false,
            'stops' => $stops,
        ])]);
    }

    public function test_flag_off_writes_nothing(): void
    {
        $day = $this->makeDay();
        $this->fakePlan([['order_day_ids' => [$day], 'route_num' => 3, 'route_pos' => 2, 'driver' => 'Личко', 'eta' => '09:10']]);

        $r = app(RouteLogixService::class)->import($this->date, 'morning');

        $this->assertFalse($r['written']);
        $this->assertSame(1, $r['days']);
        $this->assertNull(DB::table('order_days')->find($day)->ant_route_num);
        $this->assertSame(0, DB::table('delivery_routes')->count());
        Http::assertSent(fn ($req) => $req->hasHeader('Authorization', 'Bearer t0k'));
    }

    public function test_labels_and_routes_written_and_ant_routes_of_the_shift_replaced(): void
    {
        config(['services.logist.labels' => true]);
        $courier = $this->makeCourier();
        $a = $this->makeDay();
        $b = $this->makeDay();
        $foreign = 99999;

        // Ранковий маршрут ANT, який мав би піти в ставку вдруге, і вечірній — його не чіпаємо.
        $antMorning = DB::table('delivery_routes')->insertGetId([
            'date' => $this->date, 'shift' => 'morning', 'ant_route_id' => 4200, 'ant_route_num' => 1,
            'employee_id' => $courier->id, 'count_comps' => 5, 'route_time_b' => '27.10.2026 09:00',
        ]);
        $antEvening = DB::table('delivery_routes')->insertGetId([
            'date' => $this->date, 'shift' => 'evening', 'ant_route_id' => 4300, 'ant_route_num' => 1,
            'count_comps' => 5, 'route_time_b' => '27.10.2026 17:00',
        ]);

        $this->fakePlan([
            ['order_day_ids' => [$a], 'route_num' => 3, 'route_pos' => 1, 'driver' => 'Личко Володимир', 'employee_id' => $courier->id, 'eta' => '08:40'],
            ['order_day_ids' => [$b, $foreign], 'route_num' => 3, 'route_pos' => 2, 'driver' => 'Личко Володимир', 'eta' => '09:15'],
        ]);

        $r = app(RouteLogixService::class)->import($this->date, 'morning');

        $this->assertTrue($r['written']);
        $this->assertSame([$foreign], $r['unknown_days']);
        $this->assertSame(1, $r['ant_removed']);

        $dayB = DB::table('order_days')->find($b);
        $this->assertSame(3, (int) $dayB->ant_route_num);
        $this->assertSame(2, (int) $dayB->ant_route_pos);
        $this->assertSame('Личко Володимир', $dayB->ant_driver);
        $this->assertSame('routelogix', $dayB->route_source);

        $route = DeliveryRoute::where('source', 'routelogix')->sole();
        $this->assertSame($courier->id, $route->employee_id);
        $this->assertSame(2, (int) $route->count_comps);
        $this->assertSame('morning', $route->shift);
        $this->assertSame('27.10.2026 08:40', $route->route_time_b);
        $this->assertSame(800.0, (float) $route->calculated_cost);
        $this->assertSame('27.10.2026 09:15', $route->route_time_e);

        $this->assertDatabaseMissing('delivery_routes', ['id' => $antMorning]);
        $this->assertDatabaseHas('delivery_routes', ['id' => $antEvening]);
    }

    public function test_later_ant_sync_keeps_routelogix_rows(): void
    {
        config(['services.logist.labels' => true]);
        $courier = $this->makeCourier();
        $day = $this->makeDay();
        $this->fakePlan([['order_day_ids' => [$day], 'route_num' => 3, 'route_pos' => 2, 'driver' => 'Личко Володимир', 'eta' => '09:10']]);
        app(RouteLogixService::class)->import($this->date, 'morning');

        $clientId = DB::table('orders')->value('client_id');
        Http::fake([
            '*/auth*' => Http::response(['Session_Ident' => 's']),
            '*/Routes/get*' => Http::response([[
                'Route_Id' => 4200, 'Route_Num' => 1, 'Driver' => 'Личко Володимир', 'Count_Comps' => 9,
                'RouteTime_B' => '27.10.2026 09:00', 'RouteTime_E' => '27.10.2026 12:00', 'Cost_Route' => 0,
            ]]),
            '*/Route/Comps/get*' => Http::response([['PosType_Id' => 2, 'Comp_Id' => $clientId, 'Pos_Id' => 7]]),
        ]);

        $ant = app(AntLogisticsService::class);
        $ant->pullRouteDetails($this->date);
        $ant->pullRouteAssignments($this->date);

        $row = DB::table('order_days')->find($day);
        $this->assertSame(3, (int) $row->ant_route_num);
        $this->assertSame('routelogix', $row->route_source);

        // Ранковий маршрут ANT не створився поруч, наш не видалено як «застарілий».
        $this->assertSame(1, DB::table('delivery_routes')->count());
        $this->assertSame('routelogix', DB::table('delivery_routes')->value('source'));
        $this->assertSame($courier->id, (int) DB::table('delivery_routes')->value('employee_id'));
    }

    public function test_not_published_is_a_readable_error(): void
    {
        Http::fake(['logist.test/*' => Http::response(['error' => 'not_published'], 404)]);

        $this->expectExceptionMessage('ще не передав');
        app(RouteLogixService::class)->import($this->date, 'morning');
    }
}
