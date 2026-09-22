<?php

namespace Tests\Feature;

use App\Models\Order;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\BuildsCourierTestSchema;
use Tests\Support\BuildsStockTestSchema;
use Tests\TestCase;

/**
 * Тижневий звіт складається з того, що є, і не падає на порожніх таблицях.
 */
class OpsWeeklyReportTest extends TestCase
{
    use BuildsCourierTestSchema;
    use BuildsStockTestSchema;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildCourierSchema();
        $this->buildStockSchema();
        $this->travelTo(Carbon::parse('2026-09-21 09:00'));

        config()->set('services.telegram.bot_token', 'test-bot');
        config()->set('services.telegram.owner_chat_id', '100,101');
        config()->set('services.inbox.agent_token', '');
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]])]);
    }

    public function test_the_report_is_built_from_orders_and_sent_to_both_owners(): void
    {
        $clientId = $this->makeClient(['name' => 'Клієнт Тижня']);
        $catalog  = $this->seedCatalog(pricePerDay: 900);

        $order = Order::create([
            'client_id' => $clientId, 'project' => 'avocado_food', 'tariff_id' => $catalog['tariff_id'],
            'calories' => 1600, 'duration' => 5, 'start_date' => '2026-09-14', 'end_date' => '2026-09-18',
            'total_price' => 4500, 'status' => 'active', 'scale_factor' => 1.0,
        ]);

        foreach (['2026-09-14', '2026-09-15', '2026-09-16', '2026-09-17', '2026-09-18'] as $d) {
            DB::table('order_days')->insert(['order_id' => $order->id, 'date' => $d]);
        }

        $this->artisan('ops:weekly-report', ['--from' => '2026-09-14'])
            ->expectsOutputToContain('надіслано власникам')
            ->assertSuccessful();

        $sent = Http::recorded(fn ($r) => str_contains($r->url(), 'sendMessage'));
        $this->assertGreaterThanOrEqual(4, $sent->count(), 'кілька розділів × два власники');

        $headline = $sent->first()[0]['text'];
        $this->assertStringContainsString('Головне', $headline);
        $this->assertStringContainsString('Раціонів 5', $headline);
        $this->assertStringContainsString('4 500 ₴', $headline);
        $this->assertSame(['100', '101'], $sent->take(2)->map(fn ($p) => (string) $p[0]['chat_id'])->all());
    }

    public function test_dry_run_prints_and_sends_nothing(): void
    {
        $this->artisan('ops:weekly-report', ['--from' => '2026-09-14', '--dry' => true])
            ->expectsOutputToContain('Головне')
            ->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_the_report_can_go_to_one_person_only(): void
    {
        $this->artisan('ops:weekly-report', ['--from' => '2026-09-14', '--to' => '472274130'])
            ->expectsOutputToContain('надіслано 472274130')
            ->assertSuccessful();

        $chats = Http::recorded(fn ($r) => str_contains($r->url(), 'sendMessage'))
            ->map(fn ($p) => (string) $p[0]['chat_id'])->unique()->values()->all();

        $this->assertSame(['472274130'], $chats);
    }

    public function test_the_configured_recipient_replaces_the_owners(): void
    {
        config()->set('ops.weekly_report_to', ['472274130']);

        $this->artisan('ops:weekly-report', ['--from' => '2026-09-14'])
            ->expectsOutputToContain('надіслано 472274130')
            ->assertSuccessful();

        $chats = Http::recorded(fn ($r) => str_contains($r->url(), 'sendMessage'))
            ->map(fn ($p) => (string) $p[0]['chat_id'])->unique()->values()->all();

        $this->assertSame(['472274130'], $chats, 'другий власник нічого не отримує');
    }
}
