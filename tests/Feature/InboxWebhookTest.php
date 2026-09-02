<?php

namespace Tests\Feature;

use App\Jobs\SendInboxWebhook;
use App\Models\Client;
use App\Models\Order;
use App\Models\Transaction;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\BuildsInboxTestSchema;
use Tests\TestCase;

/**
 * Вебхук про оплату: CRM → Telegram Inbox.
 *
 * Оплата в CRM визначається FIFO — гроші клієнта гасять замовлення від старих
 * до нових усередині Client::recalculateOrderPaymentStatus(). is_paid там
 * ставиться через updateQuietly, тому модельні події не спрацьовують і вебхук
 * навішений прямо на цю точку.
 */
class InboxWebhookTest extends TestCase
{
    use BuildsInboxTestSchema;

    protected array $catalog;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.inbox.token', 'test-service-token');
        config()->set('services.inbox.webhook_url', 'https://inbox.example.test/api/crm/webhooks');
        config()->set('services.inbox.webhook_secret', 'shhh');

        $this->buildInboxSchema();
        $this->catalog = $this->seedCatalog(pricePerDay: 1000);
    }

    /** Замовлення з Inbox на 2 дні = 2000 грн. */
    protected function makeInboxOrder(int $clientId, array $attrs = []): Order
    {
        return Order::create(array_merge([
            'client_id'    => $clientId,
            'project'      => 'afood',
            'tariff_id'    => $this->catalog['tariff_id'],
            'calories'     => 1600,
            'duration'     => 2,
            'start_date'   => '2026-08-17',
            'end_date'     => '2026-08-18',
            'scale_factor' => 1.0,
            'source'       => 'telegram_inbox',
        ], $attrs));
    }

    public function test_it_notifies_inbox_when_an_order_becomes_paid(): void
    {
        Queue::fake();

        $clientId = $this->makeClient();
        $order    = $this->makeInboxOrder($clientId);

        // Створення замовлення само по собі оплати не дає.
        Queue::assertNotPushed(SendInboxWebhook::class);

        // Клієнт вносить гроші — FIFO гасить замовлення.
        Transaction::create([
            'type' => 'income', 'category' => 'Оплата', 'amount' => 5000,
            'date' => now(), 'order_id' => $order->id,
        ]);

        Queue::assertPushed(SendInboxWebhook::class, function (SendInboxWebhook $job) use ($order, $clientId) {
            return $job->event === 'order.payment_received'
                && $job->payload['order_id'] === $order->id
                && $job->payload['client_id'] === $clientId
                && $job->payload['payment_status'] === 'paid'
                && $job->payload['amount'] === 2000.0
                && str_starts_with($job->eventId, 'evt_');
        });
    }

    public function test_it_stays_silent_for_orders_created_in_the_crm_by_hand(): void
    {
        Queue::fake();

        $clientId = $this->makeClient();
        $order    = $this->makeInboxOrder($clientId, ['source' => null]);

        Transaction::create([
            'type' => 'income', 'category' => 'Оплата', 'amount' => 5000,
            'date' => now(), 'order_id' => $order->id,
        ]);

        Queue::assertNotPushed(SendInboxWebhook::class);
    }

    public function test_it_stays_silent_when_no_webhook_url_is_configured(): void
    {
        config()->set('services.inbox.webhook_url', '');
        Queue::fake();

        $clientId = $this->makeClient();
        $order    = $this->makeInboxOrder($clientId);

        Transaction::create([
            'type' => 'income', 'category' => 'Оплата', 'amount' => 5000,
            'date' => now(), 'order_id' => $order->id,
        ]);

        Queue::assertNotPushed(SendInboxWebhook::class);
    }

    public function test_it_does_not_repeat_the_event_while_the_order_stays_paid(): void
    {
        Queue::fake();

        $clientId = $this->makeClient();
        $order    = $this->makeInboxOrder($clientId);

        Transaction::create([
            'type' => 'income', 'category' => 'Оплата', 'amount' => 5000,
            'date' => now(), 'order_id' => $order->id,
        ]);

        // Ще один перерахунок нічого не змінює — статус той самий.
        Client::find($clientId)->recalculateOrderPaymentStatus();

        Queue::assertPushed(SendInboxWebhook::class, 1);
    }

    public function test_it_reports_a_rollback_when_payment_no_longer_covers_the_order(): void
    {
        Queue::fake();

        $clientId = $this->makeClient();
        $order    = $this->makeInboxOrder($clientId);

        $payment = Transaction::create([
            'type' => 'income', 'category' => 'Оплата', 'amount' => 5000,
            'date' => now(), 'order_id' => $order->id,
        ]);

        // Гроші повернули — замовлення знову неоплачене.
        $payment->delete();

        Queue::assertPushed(SendInboxWebhook::class, function (SendInboxWebhook $job) {
            return $job->event === 'order.payment_reverted'
                && $job->payload['payment_status'] === 'unpaid';
        });
    }

    // --- сама доставка -----------------------------------------------------

    public function test_the_job_posts_a_signed_payload(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);

        (new SendInboxWebhook(
            'order.payment_received',
            'evt_test',
            ['order_id' => 1499, 'payment_status' => 'paid', 'amount' => 4490],
            '2026-08-13T14:31:00+03:00',
        ))->handle();

        Http::assertSent(function ($request) {
            $body = json_decode($request->body(), true);

            return $request->url() === 'https://inbox.example.test/api/crm/webhooks'
                && $body['event'] === 'order.payment_received'
                && $body['event_id'] === 'evt_test'
                && $body['data']['order_id'] === 1499
                && $request->hasHeader('X-Crm-Signature');
        });
    }

    public function test_the_signature_matches_the_raw_body_that_was_actually_sent(): void
    {
        Http::fake(['*' => Http::response(['ok' => true])]);

        // Кирилиця в полі — саме той випадок, на якому ламалась би підпис,
        // якби тіло кодувалось двічі різними способами.
        (new SendInboxWebhook(
            'order.payment_received',
            'evt_test',
            ['order_id' => 1499, 'comment' => 'Оплата від клієнта «Аркадій»'],
            '2026-08-14T14:31:00+03:00',
        ))->handle();

        Http::assertSent(function ($request) {
            $raw = $request->body();

            $expected = hash_hmac('sha256', $raw, config('services.inbox.webhook_secret'));

            return $request->header('X-Crm-Signature')[0] === $expected;
        });
    }

    public function test_the_job_retries_when_the_receiver_is_down(): void
    {
        Http::fake(['*' => Http::response('boom', 500)]);

        $this->expectException(\Illuminate\Http\Client\RequestException::class);

        (new SendInboxWebhook('order.payment_received', 'evt_test', [], now()->toIso8601String()))->handle();
    }

    public function test_the_job_does_nothing_without_a_url(): void
    {
        config()->set('services.inbox.webhook_url', '');
        Http::fake();

        (new SendInboxWebhook('order.payment_received', 'evt_test', [], now()->toIso8601String()))->handle();

        Http::assertNothingSent();
    }

    public function test_an_order_created_through_the_api_is_marked_as_coming_from_inbox(): void
    {
        $clientId = $this->makeClient();

        $response = $this->postJson('/api/inbox/v1/orders', [
            'project_id' => $this->catalog['project_id'],
            'client_id'  => $clientId,
            'tariff_id'  => $this->catalog['tariff_id'],
            'calories'   => 1600,
            'days'       => 2,
            'start_date' => '2026-08-17',
        ], ['Authorization' => 'Bearer '.config('services.inbox.token')])->assertStatus(201);

        $this->assertSame('telegram_inbox', DB::table('orders')->find($response->json('order.id'))->source);
    }

    /**
     * Клієнт із боргом: грошей вистачає на старе замовлення, але не на всі.
     * Новіше замовлення не має ставати «оплаченим» лише тому, що воно дешевше —
     * саме так у проді свіже замовлення показувалось оплаченим при мінусі на
     * балансі.
     */
    public function test_a_cheaper_newer_order_does_not_jump_the_queue(): void
    {
        Queue::fake();

        $clientId = $this->makeClient();

        // Гроші внесені на перше замовлення з надлишком — надлишок іде в котел.
        $first  = $this->makeInboxOrder($clientId, ['final_price' => 1000, 'start_date' => '2026-09-01']);
        $second = $this->makeInboxOrder($clientId, ['final_price' => 5000, 'start_date' => '2026-09-05']);
        $third  = $this->makeInboxOrder($clientId, ['final_price' => 2000, 'start_date' => '2026-09-10']);

        Transaction::create([
            'type' => 'income', 'category' => 'Оплата', 'amount' => 3500,
            'date' => now(), 'order_id' => $first->id,
        ]);

        Client::find($clientId)->recalculateOrderPaymentStatus();

        // 1000 закриває перше, лишається 2500 — на друге (5000) не вистачає.
        $this->assertTrue((bool) $first->fresh()->is_paid, 'Перше оплачене своїми грошима');
        $this->assertFalse((bool) $second->fresh()->is_paid, 'На друге грошей не вистачило');
        $this->assertFalse(
            (bool) $third->fresh()->is_paid,
            'Третє дешевше за друге, але черга вже вичерпана — воно теж неоплачене',
        );
    }
}
