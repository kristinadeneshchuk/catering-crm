<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use App\Services\Ops\ManagerPerformance;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\BuildsCourierTestSchema;
use Tests\TestCase;

/**
 * Менеджери за тиждень: час відповіді з чатів Inbox, продажі з CRM,
 * факапи й бали. Цифри рахує код.
 */
class ManagerPerformanceTest extends TestCase
{
    use BuildsCourierTestSchema;

    protected Carbon $from;

    protected Carbon $to;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildCourierSchema();
        $this->travelTo(Carbon::parse('2026-09-21 09:00'));
        $this->from = Carbon::parse('2026-09-14');
        $this->to   = Carbon::parse('2026-09-20');

        config()->set('services.inbox.agent_base_url', 'https://inbox.test');
        config()->set('services.inbox.agent_token', 'ro-token');
        config()->set('ops.managers.crm_user_map', [['Ірина', '11'], ['Катя', '12']]);

        User::create(['name' => 'irina', 'email' => 'i@t.test', 'password' => 'x', 'role' => 'manager'])->forceFill(['id' => 11])->save();
        DB::table('users')->where('email', 'i@t.test')->update(['id' => 11]);
        DB::table('users')->insert(['id' => 12, 'name' => 'katya', 'email' => 'k@t.test', 'password' => 'x', 'role' => 'manager']);
    }

    /** Діалог Inbox: клієнт написав, менеджер відповів через N хвилин. */
    protected function conversation(int $id, string $manager, string $askedAt, ?string $answeredAt, string $answeredBy = 'manager'): array
    {
        $messages = [['id' => $id * 10, 'direction' => 'in', 'sender_type' => 'client', 'text' => 'Скільки коштує?', 'sent_at' => $askedAt, 'manager_name' => null]];

        if ($answeredAt) {
            $messages[] = ['id' => $id * 10 + 1, 'direction' => 'out', 'sender_type' => $answeredBy,
                'text' => 'Відповідь', 'sent_at' => $answeredAt, 'manager_name' => $answeredBy === 'manager' ? $manager : null];
        }

        return [
            'conversation' => ['id' => $id, 'assigned_manager_name' => $manager, 'chat_type' => 'private', 'tags' => []],
            'contact'      => ['first_name' => 'Клієнт', 'last_name' => (string) $id],
            'link'         => "https://inbox.test/#/conversations/{$id}",
            'messages'     => $messages,
        ];
    }

    protected function fakeInbox(array $conversations): void
    {
        $byId = collect($conversations)->keyBy(fn ($c) => $c['conversation']['id']);

        Http::fake([
            'inbox.test/api/agent/conversations?*' => Http::response([
                'conversations' => collect($conversations)->map(fn ($c) => collect($c)->except('messages')->all())->values()->all(),
                'has_more'      => false,
            ]),
            'inbox.test/api/agent/conversations/*/messages*' => fn ($request) => Http::response([
                'messages' => $byId[(int) preg_replace('/\D/', '', basename(dirname($request->url())))]['messages'] ?? [],
            ]),
        ]);
    }

    protected function orderBy(int $userId, string $createdAt, int $duration = 5, string $startDate = '2026-09-16', float $price = 4000): int
    {
        $clientId = $this->makeClient(['name' => 'К '.uniqid()]);
        $catalog  = $this->seedCatalog();

        $order = Order::create([
            'client_id' => $clientId, 'project' => 'afood', 'tariff_id' => $catalog['tariff_id'],
            'calories' => 1600, 'duration' => $duration, 'start_date' => $startDate,
            'end_date' => Carbon::parse($startDate)->addDays($duration - 1)->toDateString(),
            'total_price' => $price, 'status' => 'active', 'scale_factor' => 1.0,
        ]);

        DB::table('activity_log')->insert([
            'subject_type' => Order::class, 'subject_id' => $order->id, 'event' => 'created',
            'causer_type' => User::class, 'causer_id' => $userId, 'created_at' => $createdAt, 'updated_at' => $createdAt,
        ]);

        return $order->id;
    }

    public function test_response_time_is_measured_per_manager_in_working_hours(): void
    {
        $this->fakeInbox([
            $this->conversation(1, 'Ірина', '2026-09-15T09:00:00Z', '2026-09-15T09:05:00Z'),   // 5 хв
            $this->conversation(2, 'Ірина', '2026-09-15T12:00:00Z', '2026-09-15T12:30:00Z'),   // 30 хв
            $this->conversation(3, 'Катя',  '2026-09-16T10:00:00Z', '2026-09-16T13:30:00Z'),   // 210 хв — факап
            $this->conversation(4, 'Катя',  '2026-09-16T20:30:00Z', '2026-09-17T05:10:00Z'),   // уночі: відлік з 08:00 → 10 хв
        ]);

        $result = app(ManagerPerformance::class)->forWeek($this->from, $this->to);

        $irina = $result['managers']['Ірина'];
        $katya = $result['managers']['Катя'];

        $this->assertTrue($result['inbox_available']);
        $this->assertEqualsWithDelta(17.5, $irina['median_minutes'], 0.1);
        $this->assertSame(50, $irina['within_sla'], 'одна з двох відповідей у 15 хв');
        $this->assertSame(2, $irina['chats']);

        $this->assertEqualsWithDelta(110, $katya['median_minutes'], 0.1, '(210 + 10) / 2');
        $this->assertCount(1, collect($result['failures'])->where('kind', 'slow'));
        $this->assertStringContainsString('чекав(ла) відповіді 4 год', $result['failures'][0]['text']);
        $this->assertGreaterThan($katya['score'], $irina['score']);
    }

    public function test_an_answer_from_the_phone_goes_to_the_assigned_manager(): void
    {
        $this->fakeInbox([
            $this->conversation(5, 'Ірина', '2026-09-15T09:00:00Z', '2026-09-15T09:03:00Z', answeredBy: 'telegram'),
        ]);

        $result = app(ManagerPerformance::class)->forWeek($this->from, $this->to);

        $this->assertSame(1, $result['managers']['Ірина']['replies']);
    }

    public function test_an_unanswered_chat_is_a_failure(): void
    {
        $this->fakeInbox([
            $this->conversation(6, 'Катя', '2026-09-18T10:00:00Z', null),
        ]);

        $result = app(ManagerPerformance::class)->forWeek($this->from, $this->to);

        $this->assertSame(1, $result['managers']['Катя']['unanswered']);
        $this->assertSame('unanswered', $result['failures'][0]['kind']);
        $this->assertSame('Катя', $result['failures'][0]['manager']);
    }

    public function test_sales_come_from_the_crm_journal_and_are_matched_by_name(): void
    {
        Http::fake(['inbox.test/*' => Http::response(['conversations' => [], 'has_more' => false])]);

        $this->orderBy(11, '2026-09-15 10:00:00', duration: 3, price: 2400);
        $this->orderBy(11, '2026-09-16 10:00:00', duration: 10, price: 8000);
        $this->orderBy(12, '2026-09-17 10:00:00', duration: 5, price: 4000);

        $result = app(ManagerPerformance::class)->forWeek($this->from, $this->to);

        $irina = $result['managers']['Ірина'];
        $this->assertSame(2, $irina['orders_created']);
        $this->assertSame(1, $irina['trials']);
        // Ціну замовлення CRM рахує сама з тарифу (1 000 ₴/день): 3 000 + 10 000.
        $this->assertEquals(13000, $irina['orders_sum']);
        $this->assertSame(1, $result['managers']['Катя']['orders_created']);
    }

    public function test_an_order_for_tomorrow_after_eleven_is_a_failure(): void
    {
        Http::fake(['inbox.test/*' => Http::response(['conversations' => [], 'has_more' => false])]);

        $this->orderBy(12, '2026-09-15 14:20:00', startDate: '2026-09-16');
        $this->orderBy(11, '2026-09-15 10:20:00', startDate: '2026-09-16'); // до дедлайну

        $result = app(ManagerPerformance::class)->forWeek($this->from, $this->to);

        $late = collect($result['failures'])->where('kind', 'late_order');
        $this->assertCount(1, $late);
        $this->assertSame('Катя', $late->first()['manager']);
        $this->assertStringContainsString('після дедлайну 11:00', $late->first()['text']);
        $this->assertSame(1, $result['managers']['Катя']['failures']);
    }

    public function test_without_inbox_the_score_uses_only_what_is_known(): void
    {
        config()->set('services.inbox.agent_token', '');
        Http::fake();

        $this->orderBy(11, '2026-09-15 10:00:00');

        $result = app(ManagerPerformance::class)->forWeek($this->from, $this->to);

        $this->assertFalse($result['inbox_available']);
        $this->assertNull($result['managers']['Ірина']['median_minutes']);
        $this->assertGreaterThan(0, $result['managers']['Ірина']['score']);
        Http::assertNothingSent();
    }

    public function test_every_failure_costs_five_points(): void
    {
        $perf = app(ManagerPerformance::class);
        $team = ['renewal_rate' => 50, 'orders_created' => 4, 'managers_selling' => 2, 'median_minutes' => 10, 'unanswered' => 0];
        $base = ['median_minutes' => 10.0, 'within_sla' => 100, 'renewal_rate' => 50, 'orders_created' => 2, 'ended' => 2, 'renewed' => 1, 'failures' => 0];

        $clean = $perf->score($base, $team);
        $two   = $perf->score(['failures' => 2] + $base, $team);

        $this->assertSame(100, $clean);
        $this->assertSame(90, $two);
    }
}
