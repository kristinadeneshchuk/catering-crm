<?php

namespace Tests\Feature;

use App\Models\LunchOrder;
use App\Services\AntLogisticsService;
use App\Services\Kitchen\LunchDelivery;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\Support\BuildsSmsTestSchema;
use Tests\TestCase;

/**
 * Корпоративні обіди як точки доставки в заявці ANT: компанія, привʼязана до
 * клієнта з адресою, стає точкою; решта — у списку «пропущено».
 */
class LunchDeliveryAntTest extends TestCase
{
    use BuildsSmsTestSchema;

    private string $day = '2026-11-16';

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSmsSchema();
        (require database_path('migrations/2026_11_11_100000_create_lunch_orders.php'))->up();
        if (! Schema::hasColumn('client_addresses', 'ant_comp_id')) {
            Schema::table('client_addresses', fn ($t) => $t->string('ant_comp_id')->nullable());
        }

        Http::fake([
            '*auth*' => Http::response(['Session_Ident' => 'sess-test']),
            '*'      => Http::response(['ok' => true]),
        ]);
    }

    private function company(string $window = '08:30–09:30', bool $linked = true, bool $address = true, int $sets = 4): int
    {
        $clientId = null;
        if ($linked) {
            $clientId = DB::table('clients')->insertGetId(['name' => 'ТЕСТ від ХП', 'phone' => '0500000000']);
            if ($address) {
                DB::table('client_addresses')->insert(['client_id' => $clientId, 'is_default' => true, 'ant_comp_id' => (string) $clientId]);
            }
        }

        return LunchOrder::create([
            'date' => $this->day, 'company_id' => random_int(100, 99999), 'company_name' => 'ТЕСТ від ХП', 'client_id' => $clientId,
            'sets' => $sets, 'amount' => 0, 'delivery_window' => $window, 'contact' => 'Тест 0500000000',
        ])->client_id ?? 0;
    }

    /** Рядки точок, які пішли в ANT. */
    private function sentRows(): array
    {
        return collect(Http::recorded())
            ->filter(fn ($pair) => str_contains($pair[0]->url(), 'Request/Comps/edit'))
            ->flatMap(fn ($pair) => $pair[0]->data()['rows'] ?? [])
            ->values()->all();
    }

    public function test_window_and_shift_are_read_from_lunch_hub_text(): void
    {
        $this->assertSame(['08:30', '09:30'], LunchDelivery::window('08:30–09:30'));
        $this->assertSame(['11:00', '11:30'], LunchDelivery::window('11:00-11:30'));
        $this->assertSame(['09:00', '12:00'], LunchDelivery::window(null));
        $this->assertSame('morning', LunchDelivery::shiftOf(['08:30', '09:30']));
        $this->assertSame('evening', LunchDelivery::shiftOf(['17:00', '18:00']));
    }

    public function test_a_linked_company_becomes_a_delivery_point(): void
    {
        $clientId = $this->company();

        $result = app(AntLogisticsService::class)->pushDailyOrders($this->day, 'morning');

        $rows = $this->sentRows();
        $this->assertCount(1, $rows);
        $this->assertSame((string) $clientId, (string) $rows[0]['Comp_Id']);
        $this->assertSame('08:30:00', $rows[0]['TimeWork_Beg_Req']);
        $this->assertSame('09:30:00', $rows[0]['TimeWork_End_Req']);
        $this->assertEquals(4.0, $rows[0]['Products'][0]['Qty']);
        $this->assertStringContainsString('ОБІДИ: 4 компл.', $rows[0]['Note']);
        $this->assertSame(1, $result['pushed']);
        $this->assertSame([], $result['skipped']);
    }

    public function test_a_morning_lunch_does_not_go_into_the_evening_shift(): void
    {
        $this->company();

        $result = app(AntLogisticsService::class)->pushDailyOrders($this->day, 'evening');

        $this->assertSame([], $this->sentRows());
        $this->assertSame(0, $result['total']);
    }

    public function test_unlinked_or_addressless_companies_are_reported_not_sent(): void
    {
        $this->company(linked: false);
        $this->company(address: false);
        $this->company();

        $result = app(AntLogisticsService::class)->pushDailyOrders($this->day, 'all');

        $this->assertCount(1, $this->sentRows());
        $this->assertEqualsCanonicalizing(
            ['lunch_company_not_linked', 'lunch_client_without_address'],
            array_column($result['skipped'], 'reason'),
        );
    }

    public function test_rations_go_out_unchanged_next_to_the_lunch_point(): void
    {
        $this->deliveryDate = $this->day;
        $ration = $this->makeOrderDay(order: ['delivery_time' => '07:00-09:00']);

        app(AntLogisticsService::class)->pushDailyOrders($this->day, 'morning');
        $before = $this->sentRows();
        $this->assertCount(1, $before);

        $this->company();
        (new AntLogisticsService())->pushDailyOrders($this->day, 'morning');
        $all   = $this->sentRows();
        $after = array_slice($all, count($all) - 2);
        $this->assertCount(3, $all, 'Перша відправка — 1 точка, друга — 2');

        $this->assertCount(2, $after);
        $this->assertSame($before[0], $after[0], 'Точка раціону не має змінитись від появи обіду');
        $this->assertSame((string) $ration['client_id'], (string) $after[0]['Comp_Id']);
        $this->assertStringContainsString('ОБІДИ', $after[1]['Note']);
    }

    public function test_a_broken_lunch_table_never_blocks_the_rations(): void
    {
        $this->deliveryDate = $this->day;
        $this->makeOrderDay(order: ['delivery_time' => '07:00-09:00']);
        $this->company();
        $this->assertTrue(\App\Support\SchemaReady::has('lunch_orders'));
        Schema::rename('lunch_orders', 'lunch_orders_broken');   // обіди «зламались» посеред роботи

        $result = app(AntLogisticsService::class)->pushDailyOrders($this->day, 'morning');

        $this->assertCount(1, $this->sentRows());
        $this->assertSame(1, $result['pushed']);
    }

    public function test_a_lunch_only_company_gets_its_address_into_the_ant_directory(): void
    {
        $this->day = now()->addDay()->toDateString();
        $clientId = $this->company();
        DB::table('client_addresses')->where('client_id', $clientId)->update(['ant_comp_id' => null]);

        $synced = app(AntLogisticsService::class)->syncAllClients();

        $rows = collect(Http::recorded())
            ->filter(fn ($pair) => str_contains($pair[0]->url(), 'Directory/Comps/edit'))
            ->flatMap(fn ($pair) => $pair[0]->data()['rows'] ?? [])->values();

        $this->assertSame(1, $synced);
        $this->assertSame((string) $clientId, (string) $rows[0]['Comp_Id']);
        $this->assertSame('08:30:00', $rows[0]['TimeWork_Beg']);
        $this->assertSame('09:30:00', $rows[0]['TimeWork_End']);
        $this->assertSame((string) $clientId, DB::table('client_addresses')->where('client_id', $clientId)->value('ant_comp_id'));
    }

    public function test_another_day_is_not_sent(): void
    {
        $this->company();

        app(AntLogisticsService::class)->pushDailyOrders('2026-11-17', 'all');

        $this->assertSame([], $this->sentRows());
    }
}
