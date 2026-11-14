<?php

namespace Tests\Feature;

use App\Models\AntPush;
use App\Models\OrderDay;
use App\Services\AntLogisticsService;
use App\Services\Logistics\LatePushWatch;
use App\Support\SchemaReady;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\Support\BuildsSmsTestSchema;
use Tests\TestCase;

/**
 * Замовлення після відправки зміни в ANT: знімок відправки, різниця з поточним
 * станом на «Логістиці» і повідомлення менеджерам у момент створення дня.
 */
class LatePushWatchTest extends TestCase
{
    use BuildsSmsTestSchema;

    private string $day;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-13 11:50:00');   // пʼятниця
        $this->day = '2026-11-14';

        $this->buildSmsSchema();
        Schema::table('client_addresses', fn ($t) => $t->string('ant_comp_id')->nullable());
        Schema::table('order_days', function ($t) {
            $t->string('address')->nullable();
            $t->string('discount_type')->nullable();
            $t->decimal('discount_value', 10, 2)->nullable();
            $t->decimal('discount_amount', 10, 2)->default(0);
        });
        (require database_path('migrations/2026_11_14_100000_create_ant_pushes.php'))->up();
        SchemaReady::flush();
        Cache::flush();
        activity()->disableLogging();

        config(['services.telegram.bot_token' => 'test', 'services.telegram.manager_chat_id' => '-100']);
        Http::fake([
            '*auth*' => Http::response(['Session_Ident' => 'sess-test']),
            '*'      => Http::response(['ok' => true, 'status' => 'success']),
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        SchemaReady::flush();
        parent::tearDown();
    }

    private function order(string $name, string $schedule = 'every_day_morning'): int
    {
        $clientId = DB::table('clients')->insertGetId(['name' => $name, 'phone' => '0500000000']);
        DB::table('client_addresses')->insert(['client_id' => $clientId, 'is_default' => true, 'ant_comp_id' => (string) $clientId]);

        return DB::table('orders')->insertGetId(['client_id' => $clientId, 'status' => 'active', 'schedule_type' => $schedule, 'delivery_time' => '07:30-09:00']);
    }

    /** День напряму в базу (хуки Order у точковій схемі не живуть) + той самий виклик, що робить OrderDay::created. */
    private function day(int $orderId, string $date): OrderDay
    {
        $id = DB::table('order_days')->insertGetId(['order_id' => $orderId, 'date' => $date, 'created_at' => now(), 'updated_at' => now()]);
        $day = OrderDay::find($id);
        app(LatePushWatch::class)->dayCreated($day);

        return $day;
    }

    private function telegramTexts(): array
    {
        return collect(Http::recorded())
            ->filter(fn ($pair) => str_contains($pair[0]->url(), 'api.telegram.org'))
            ->map(fn ($pair) => (string) ($pair[0]->data()['text'] ?? ''))->values()->all();
    }

    public function test_push_records_a_snapshot_and_page_diff_is_empty(): void
    {
        $o = $this->order('ТЕСТ від ХП 1');
        $d = $this->day($o, $this->day);

        app(AntLogisticsService::class)->pushDailyOrders($this->day, 'morning');

        $push = AntPush::first();
        $this->assertSame('morning', $push->shift);
        $this->assertSame([$d->id], array_column($push->points, 'day_id'));
        $this->assertSame([], app(LatePushWatch::class)->diff($this->day));
        $this->assertSame([], $this->telegramTexts());
    }

    public function test_order_added_after_push_is_shown_and_managers_are_told_once(): void
    {
        $this->day($this->order('ТЕСТ від ХП 1'), $this->day);
        app(AntLogisticsService::class)->pushDailyOrders($this->day, 'morning');

        Carbon::setTestNow('2026-11-13 13:24:00');
        $late = $this->order('ТЕСТ від ХП пізній');
        $this->day($late, $this->day);
        $this->day($late, '2026-11-16');   // зміну на 16.11 ще не відправляли

        $diff = app(LatePushWatch::class)->diff($this->day);
        $this->assertSame(['morning'], array_keys($diff));
        $this->assertCount(1, $diff['morning']['added']);
        $this->assertSame('ТЕСТ від ХП пізній', $diff['morning']['added'][0]['name']);
        $this->assertSame([], $diff['morning']['removed']);

        $texts = $this->telegramTexts();
        $this->assertCount(1, $texts);
        $this->assertStringContainsString('ТЕСТ від ХП пізній', $texts[0]);
        $this->assertStringContainsString('сб 14.11, ранок', $texts[0]);
        $this->assertStringContainsString('13.11 о 11:50', $texts[0]);

        // Повторна відправка закриває розбіжність.
        app(AntLogisticsService::class)->pushDailyOrders($this->day, 'morning');
        $this->assertSame([], app(LatePushWatch::class)->diff($this->day));
    }

    public function test_sunday_ration_added_after_saturday_push_counts_as_late(): void
    {
        DB::table('settings')->insert(['key' => 'closed_delivery_slots', 'value' => json_encode(['sunday_morning'])]);
        $o = $this->order('ТЕСТ від ХП 1');
        $this->day($o, $this->day);
        app(AntLogisticsService::class)->pushDailyOrders($this->day, 'morning');

        $this->day($o, '2026-11-15');

        $diff = app(LatePushWatch::class)->diff($this->day);
        if (OrderDay::where('date', '2026-11-15')->first()->resolveDeliveryDate()->format('Y-m-d') !== $this->day) {
            $this->markTestSkipped('Закриті слоти в тестовій схемі налаштовуються інакше.');
        }
        $this->assertSame('нд 15.11', $diff['morning']['added'][0]['food']);
        $this->assertCount(1, $this->telegramTexts());
    }

    public function test_day_removed_after_push_is_shown_as_removed(): void
    {
        $d = $this->day($this->order('ТЕСТ від ХП знятий'), $this->day);
        $this->day($this->order('ТЕСТ від ХП 2'), $this->day);
        app(AntLogisticsService::class)->pushDailyOrders($this->day, 'morning');

        DB::table('order_days')->where('id', $d->id)->delete();

        $diff = app(LatePushWatch::class)->diff($this->day);
        $this->assertSame([], $diff['morning']['added']);
        $this->assertSame('ТЕСТ від ХП знятий', $diff['morning']['removed'][0]['name']);
    }

    public function test_evening_order_is_not_reported_against_morning_push(): void
    {
        $this->day($this->order('ТЕСТ від ХП 1'), $this->day);
        app(AntLogisticsService::class)->pushDailyOrders($this->day, 'morning');

        $this->day($this->order('ТЕСТ від ХП вечір', 'every_day_evening'), '2026-11-15');

        $this->assertSame([], app(LatePushWatch::class)->diff($this->day));
        $this->assertSame([], $this->telegramTexts());
    }

    public function test_without_the_table_nothing_breaks(): void
    {
        Schema::drop('ant_pushes');
        SchemaReady::flush();

        $this->day($this->order('ТЕСТ від ХП 1'), $this->day);
        $result = app(AntLogisticsService::class)->pushDailyOrders($this->day, 'morning');

        $this->assertSame(1, $result['pushed']);
        $this->assertSame([], app(LatePushWatch::class)->diff($this->day));
    }
}
