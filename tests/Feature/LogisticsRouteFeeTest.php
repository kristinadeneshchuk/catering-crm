<?php

namespace Tests\Feature;

use App\Models\DeliveryRoute;
use App\Models\Setting;
use App\Services\AntLogisticsService;
use App\Services\ScheduleService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\BuildsSmsTestSchema;
use Tests\TestCase;

/**
 * Доплати «дальня доставка» та життєвий цикл маршрутів з ANT.
 *
 * Головна пастка: Route_Num в ANT перенумеровується при кожній перебудові
 * маршрутів, тож зв'язка «номер + водій» розсипалась і доплата діставалась
 * нікому (або не тому). Стабільний ключ — ant_route_id.
 */
class LogisticsRouteFeeTest extends TestCase
{
    use BuildsSmsTestSchema;

    protected string $deliveryDate = '2026-08-05';

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSmsSchema();
        Setting::create(['key' => ScheduleService::CLOSED_SLOTS_KEY, 'value' => '[]']);
        ScheduleService::clearClosedSlotsCache();
    }

    // --- extraDeliveryFee -------------------------------------------------

    public function test_fee_follows_the_stable_route_id_even_after_renumbering(): void
    {
        // Маршрут після перебудови отримав номер 1, а день записаний ще зі
        // старим номером 2 — але стабільний id у них спільний.
        $routeId = $this->makeRoute(['ant_route_id' => 'ant-777', 'ant_route_num' => 1, 'driver_name' => 'Коток Анастасія ']);
        $this->makeOrderDay(day: [
            'ant_route_num' => 2, 'ant_route_id' => 'ant-777',
            'ant_driver' => 'Хтось Інший', 'extra_delivery_fee' => 150,
        ]);

        $route = DeliveryRoute::find($routeId);
        $this->assertSame(150.0, $route->extraDeliveryFee());
    }

    public function test_legacy_day_without_route_id_still_matches_by_num_and_driver(): void
    {
        $routeId = $this->makeRoute(['ant_route_id' => 'ant-1', 'ant_route_num' => 3, 'driver_name' => 'Іванов І.І.']);
        $this->makeOrderDay(day: [
            'ant_route_num' => 3, 'ant_route_id' => null,
            'ant_driver' => 'Іванов І.І.', 'extra_delivery_fee' => 150,
        ]);

        $this->assertSame(150.0, DeliveryRoute::find($routeId)->extraDeliveryFee());
    }

    public function test_day_with_route_id_is_not_double_counted_by_the_legacy_pair(): void
    {
        // День зі стабільним id належить маршруту А; маршрут Б випадково має
        // ту саму пару «номер + водій» (перенумерація). Доплата має впасти
        // рівно один раз — на А.
        $a = $this->makeRoute(['ant_route_id' => 'ant-a', 'ant_route_num' => 5, 'driver_name' => 'Іванов І.І.']);
        $b = $this->makeRoute(['ant_route_id' => 'ant-b', 'ant_route_num' => 5, 'driver_name' => 'Іванов І.І.']);
        $this->makeOrderDay(day: [
            'ant_route_num' => 5, 'ant_route_id' => 'ant-a',
            'ant_driver' => 'Іванов І.І.', 'extra_delivery_fee' => 150,
        ]);

        $this->assertSame(150.0, DeliveryRoute::find($a)->extraDeliveryFee());
        $this->assertSame(0.0, DeliveryRoute::find($b)->extraDeliveryFee());
    }

    // --- pullRouteDetails: чистка залиплих маршрутів ------------------------

    public function test_routes_dropped_by_ant_rebuild_are_removed(): void
    {
        // Чорновий ВЕЧІРНІЙ маршрут, який ANT після перебудови більше не віддає.
        // Час старту тут принциповий: чистка діє лише в межах тих змін, які ANT
        // цього разу показав. Ранковий маршрут у цій же відповіді вцілів би —
        // логіст видаляє ранкові в ANT, щоб побудувати вечірні, і CRM не має
        // сприймати це як «маршруту більше немає».
        $stale = $this->makeRoute([
            'ant_route_id' => '4298', 'ant_route_num' => 1,
            'driver_name' => 'Чернетка', 'route_time_b' => '05.08.2026 17:30',
        ]);
        // Живий — прийде у відповіді й має лишитись.
        $alive = $this->makeRoute([
            'ant_route_id' => '4306', 'ant_route_num' => 1,
            'driver_name' => 'Коток Анастасія ', 'route_time_b' => '05.08.2026 17:47',
        ]);
        // Ранковий — ANT його не віддасть, і чіпати його не можна.
        $morning = $this->makeRoute([
            'ant_route_id' => '4200', 'ant_route_num' => 1,
            'driver_name' => 'Ранковий', 'route_time_b' => '05.08.2026 09:00',
        ]);

        Http::fake([
            '*/auth*' => Http::response(['Session_Ident' => 'sess-1']),
            '*/Routes/get*' => Http::response([[
                'Route_Id' => '4306', 'Route_Num' => 1,
                'Driver' => 'Коток Анастасія ', 'Count_Comps' => 8,
                'RouteTime_B' => '05.08.2026 17:47', 'RouteTime_E' => '05.08.2026 21:05',
                'Cost_Route' => 0,
            ]]),
        ]);

        app(AntLogisticsService::class)->pullRouteDetails($this->deliveryDate);

        $this->assertDatabaseMissing('delivery_routes', ['id' => $stale]);
        $this->assertDatabaseHas('delivery_routes', ['id' => $alive]);
        $this->assertDatabaseHas('delivery_routes', ['id' => $morning]);
    }

    // --- матч водія --------------------------------------------------------

    public function test_driver_matches_via_union_of_card_name_and_ant_name(): void
    {
        // Ім'я в картці іншою мовою, ant_driver_name — лише ім'я. Окремо жодне
        // не покриває рядок з ANT, разом — покривають.
        $id = DB::table('employees')->insertGetId([
            'name' => 'Коток Анастасия', 'ant_driver_name' => 'Анастасія',
            'position' => 'courier', 'is_active' => 1,
        ]);

        $match = AntLogisticsService::matchDriverToEmployee(
            'Коток Анастасія ',
            \App\Models\Employee::all(),
        );

        $this->assertNotNull($match);
        $this->assertSame($id, $match->id);
    }
}
