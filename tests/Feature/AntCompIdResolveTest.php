<?php

namespace Tests\Feature;

use App\Services\AntLogisticsService;
use App\Services\ScheduleService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\Support\BuildsCourierTestSchema;
use Tests\TestCase;

/**
 * Забір маршрутів з ANT: точка на неосновній адресі має складений Comp_Id
 * («клієнт + адреса»), і її треба привʼязати до клієнта, а не загубити.
 */
class AntCompIdResolveTest extends TestCase
{
    use BuildsCourierTestSchema;

    private string $date = '2026-10-27';

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildCourierSchema();
        (require database_path('migrations/2026_11_03_100000_add_route_source_for_routelogix.php'))->up();
        if (! Schema::hasTable('client_addresses')) {
            Schema::create('client_addresses', function ($t) {
                $t->id();
                $t->unsignedBigInteger('client_id');
                $t->boolean('is_default')->default(false);
                $t->string('ant_comp_id')->nullable();
                $t->timestamps();
            });
        } elseif (! Schema::hasColumn('client_addresses', 'ant_comp_id')) {
            Schema::table('client_addresses', fn ($t) => $t->string('ant_comp_id')->nullable());
        }
        $this->setting(ScheduleService::CLOSED_SLOTS_KEY, '[]');
        ScheduleService::clearClosedSlotsCache();
    }

    /** @return array{0:int,1:int} клієнт і день */
    private function clientWithDay(): array
    {
        $client = $this->makeClient();
        $order  = DB::table('orders')->insertGetId(['client_id' => $client, 'status' => 'active', 'schedule_type' => 'every_day_morning']);

        return [$client, DB::table('order_days')->insertGetId(['order_id' => $order, 'date' => $this->date])];
    }

    private function pullWithComp(string|int $compId): void
    {
        Http::fake([
            '*/auth*' => Http::response(['Session_Ident' => 's']),
            '*/Routes/get*' => Http::response([[
                'Route_Id' => 4200, 'Route_Num' => 1, 'Driver' => 'Тест Курʼєр', 'Count_Comps' => 1,
                'RouteTime_B' => '27.10.2026 09:00', 'RouteTime_E' => '27.10.2026 12:00', 'Cost_Route' => 0,
            ]]),
            '*/Route/Comps/get*' => Http::response([['PosType_Id' => 2, 'Comp_Id' => $compId, 'Pos_Id' => 7]]),
        ]);

        $ant = app(AntLogisticsService::class);
        $ant->pullRouteDetails($this->date);
        $ant->pullRouteAssignments($this->date);
    }

    public function test_point_on_a_non_default_address_gets_its_route(): void
    {
        [$client, $day] = $this->clientWithDay();
        $addr = DB::table('client_addresses')->insertGetId(['client_id' => $client, 'is_default' => false]);
        $comp = sprintf('%d%04d', $client, $addr);
        DB::table('client_addresses')->where('id', $addr)->update(['ant_comp_id' => $comp]);

        $this->pullWithComp($comp);

        $row = DB::table('order_days')->find($day);
        $this->assertSame('4200', (string) $row->ant_route_id);
        $this->assertSame(7, (int) $row->ant_route_pos);
        $this->assertDatabaseHas('route_stops', ['client_id' => $client, 'order_day_id' => $day]);
    }

    public function test_old_composite_number_still_resolves_after_address_became_default(): void
    {
        [$client, $day] = $this->clientWithDay();
        $addr = DB::table('client_addresses')->insertGetId(['client_id' => $client, 'is_default' => true, 'ant_comp_id' => (string) $client]);

        $this->pullWithComp(sprintf('%d%04d', $client, $addr));

        $this->assertSame('4200', (string) DB::table('order_days')->find($day)->ant_route_id);
    }

    public function test_plain_client_id_keeps_working(): void
    {
        [$client, $day] = $this->clientWithDay();

        $this->pullWithComp($client);

        $this->assertSame('4200', (string) DB::table('order_days')->find($day)->ant_route_id);
    }

    public function test_unknown_or_garbage_comp_id_is_ignored(): void
    {
        [, $day] = $this->clientWithDay();

        $this->pullWithComp('99990001');
        $this->assertNull(DB::table('order_days')->find($day)->ant_route_id);
        $this->assertSame(0, app(AntLogisticsService::class)->clientIdFromCompId('abc'));
        $this->assertSame(0, app(AntLogisticsService::class)->clientIdFromCompId(null));
    }
}
