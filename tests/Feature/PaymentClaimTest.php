<?php

namespace Tests\Feature;

use App\Filament\Pages\PaymentClaims;
use App\Jobs\SendInboxWebhook;
use App\Models\Account;
use App\Models\Client;
use App\Models\Order;
use App\Models\PaymentClaim;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Payments\PaymentClaimService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\BuildsInboxTestSchema;
use Tests\TestCase;

/**
 * Оплату підтверджує тільки менеджер.
 *
 * Клієнт пише агенту «оплатив» чи «передав готівкою курʼєру», курʼєр вписує
 * готівку у звіт — усе це лише заяви. Гроші зʼявляються в CRM, коли менеджер
 * натисне «Підтвердити» і вибере касу.
 */
class PaymentClaimTest extends TestCase
{
    use BuildsInboxTestSchema;

    protected array $catalog;

    protected Client $client;

    protected User $manager;

    protected PaymentClaimService $claims;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.inbox.token', 'test-service-token');
        config()->set('services.inbox.webhook_url', 'https://inbox.example.test/api/crm/webhooks');
        config()->set('services.inbox.webhook_secret', 'shhh');

        // Черга в тестах синхронна: без фейку кожне підтвердження реально
        // стукало б на inbox.example.test і висіло до таймауту.
        Queue::fake();

        $this->buildInboxSchema();

        Schema::create('users', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('email')->unique();
            $t->string('password');
            $t->string('role')->default('manager');
            $t->rememberToken();
            $t->timestamps();
        });

        Schema::create('employees', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('position')->nullable();
            $t->decimal('balance', 10, 2)->default(0);
            $t->timestamps();
        });

        $this->catalog = $this->seedCatalog(pricePerDay: 1000);
        $this->client  = Client::find($this->makeClient());
        $this->manager = $this->user('manager');
        $this->claims  = app(PaymentClaimService::class);

        $this->actingAs($this->manager);
    }

    protected function user(string $role): User
    {
        $u = new User(['name' => ucfirst($role), 'email' => $role.uniqid().'@test.local']);
        $u->role = $role;
        $u->password = bcrypt('secret');
        $u->save();

        return $u;
    }

    /** Замовлення на 5 000 грн, оформлене через Inbox — щоб летіли вебхуки. */
    protected function order(): Order
    {
        return Order::create([
            'client_id' => $this->client->id, 'project' => 'afood',
            'tariff_id' => $this->catalog['tariff_id'], 'calories' => 1600,
            'duration' => 5, 'start_date' => '2026-09-14', 'end_date' => '2026-09-18',
            'scale_factor' => 1.0, 'source' => 'telegram_inbox',
        ]);
    }

    protected function claim(Order $order, string $source = PaymentClaim::SOURCE_CLIENT_TRANSFER, float $amount = 5000, array $extra = []): PaymentClaim
    {
        return $this->claims->create($order, array_merge([
            'source' => $source, 'amount' => $amount,
            'reported_by_type' => PaymentClaim::REPORTED_BY_AGENT,
        ], $extra));
    }

    protected function cash(): Account
    {
        return Account::find($this->makeAccount('cash', 'Готівка'));
    }

    // --- обовʼязкові перевірки ТЗ -------------------------------------------

    public function test_a_claim_never_marks_the_order_paid(): void
    {
        $order = $this->order();

        $this->claim($order);

        $this->assertFalse((bool) $order->fresh()->is_paid, 'заява — не гроші');
        $this->assertSame(0, Transaction::where('order_id', $order->id)->where('type', 'income')->count());
    }

    public function test_confirming_creates_a_transaction_with_a_cash_box(): void
    {
        $order   = $this->order();
        $account = $this->cash();
        $claim   = $this->claim($order);

        $transaction = $this->claims->confirm($claim, $account, $this->manager);

        $this->assertSame($account->id, $transaction->account_id);
        $this->assertSame($order->id, $transaction->order_id);
        $this->assertSame($this->client->id, $transaction->client_id, 'client_id тепер доходить до транзакції');
        $this->assertSame('transfer', $transaction->method, 'і спосіб оплати теж');
        $this->assertTrue((bool) $order->fresh()->is_paid);
        $this->assertSame(PaymentClaim::STATUS_CONFIRMED, $claim->fresh()->status);
    }

    public function test_a_role_without_access_gets_403(): void
    {
        $this->actingAs($this->user('cook'));

        $this->get('/admin/payment-claims')->assertForbidden();
    }

    public function test_the_admin_survives_before_the_migration_runs(): void
    {
        // Вікно, яке буває завжди: на horenko між git pull і migrate, на afood і
        // shinshin — завжди (там деплой без міграцій). Бейдж у меню рахується на
        // кожній сторінці — без запобіжника впала б уся бічна панель.
        Schema::drop('payment_claims');
        \App\Support\SchemaReady::flush();

        $this->assertNull(PaymentClaims::getNavigationBadge());
        $this->assertFalse(PaymentClaims::canAccess());

        $this->getJson("/api/inbox/v1/clients/{$this->client->id}/orders", $this->authHeaders())
            ->assertOk();
    }

    public function test_a_cook_cannot_confirm_through_the_service_either(): void
    {
        $claim = $this->claim($this->order());

        $this->expectException(AuthorizationException::class);

        $this->claims->confirm($claim, $this->cash(), $this->user('cook'));
    }

    // --- підтвердження -------------------------------------------------------

    public function test_the_manager_can_correct_the_amount(): void
    {
        $claim = $this->claim($this->order(), amount: 5000);

        $tx = $this->claims->confirm($claim, $this->cash(), $this->manager, 4800);

        $this->assertEquals(4800, $tx->amount);
        // Заявлену суму не перезаписуємо — інакше розбіжність зникла б з історії.
        $this->assertEquals(5000, $claim->fresh()->amount);
        $this->assertStringContainsString('заявлено 5 000.00', $tx->comment);
    }

    public function test_a_claim_cannot_be_confirmed_twice(): void
    {
        $claim = $this->claim($this->order());
        $this->claims->confirm($claim, $this->cash(), $this->manager);

        $this->expectException(ValidationException::class);

        $this->claims->confirm($claim->fresh(), $this->cash(), $this->manager);
    }

    public function test_partial_payments_are_allowed(): void
    {
        $order = $this->order();

        $this->claims->confirm($this->claim($order, amount: 2000), $this->cash(), $this->manager);
        $this->assertFalse((bool) $order->fresh()->is_paid);

        $this->claims->confirm($this->claim($order, amount: 3000), $this->cash(), $this->manager);
        $this->assertTrue((bool) $order->fresh()->is_paid);
    }

    public function test_rejecting_needs_a_reason_and_leaves_the_order_unpaid(): void
    {
        $order = $this->order();
        $claim = $this->claim($order);

        try {
            $this->claims->reject($claim, $this->manager, '   ');
            $this->fail('порожня причина має не пройти');
        } catch (ValidationException) {
        }

        $this->claims->reject($claim, $this->manager, 'Не бачимо надходження на 5 000 грн');

        $this->assertSame(PaymentClaim::STATUS_REJECTED, $claim->fresh()->status);
        $this->assertFalse((bool) $order->fresh()->is_paid);
    }

    // --- пара «клієнт + курʼєр» ---------------------------------------------

    public function test_client_and_courier_cash_pair_up(): void
    {
        $order = $this->order();

        $client  = $this->claim($order, PaymentClaim::SOURCE_CLIENT_CASH, 5000);
        $courier = $this->claim($order, PaymentClaim::SOURCE_COURIER_CASH, 5000, ['employee_id' => 7]);

        $this->assertSame($courier->id, $client->fresh()->paired_claim_id);
        $this->assertSame($client->id, $courier->fresh()->paired_claim_id);
        // Клієнт курʼєра не знає — беремо в напарника.
        $this->assertSame(7, $client->fresh()->employee_id);
        $this->assertFalse($client->fresh()->hasDiscrepancy());
    }

    public function test_a_pair_is_paid_once_not_twice(): void
    {
        // Те, заради чого пара існує: одні гроші, описані двічі.
        $order   = $this->order();
        $client  = $this->claim($order, PaymentClaim::SOURCE_CLIENT_CASH, 5000);
        $courier = $this->claim($order, PaymentClaim::SOURCE_COURIER_CASH, 5000);

        $this->claims->confirm($client->fresh(), $this->cash(), $this->manager);

        $this->assertSame(1, Transaction::where('order_id', $order->id)->where('type', 'income')->count());
        $this->assertSame(PaymentClaim::STATUS_SUPERSEDED, $courier->fresh()->status);
    }

    public function test_different_amounts_raise_a_discrepancy(): void
    {
        // Кейс «9400 проти 9300» з чатів.
        $order  = $this->order();
        $client = $this->claim($order, PaymentClaim::SOURCE_CLIENT_CASH, 9400);
        $this->claim($order, PaymentClaim::SOURCE_COURIER_CASH, 9300);

        $this->assertTrue($client->fresh()->hasDiscrepancy());
    }

    public function test_rejecting_a_pair_rejects_both_sides(): void
    {
        $order   = $this->order();
        $client  = $this->claim($order, PaymentClaim::SOURCE_CLIENT_CASH, 5000);
        $courier = $this->claim($order, PaymentClaim::SOURCE_COURIER_CASH, 5000);

        $this->claims->reject($client->fresh(), $this->manager, 'Курʼєр готівку не здав');

        $this->assertSame(PaymentClaim::STATUS_REJECTED, $courier->fresh()->status);
    }

    public function test_the_list_shows_a_pair_as_one_row(): void
    {
        $order = $this->order();
        $this->claim($order, PaymentClaim::SOURCE_CLIENT_CASH, 5000);
        $this->claim($order, PaymentClaim::SOURCE_COURIER_CASH, 5000);
        $this->claim($this->order(), PaymentClaim::SOURCE_CLIENT_TRANSFER, 5000);

        Livewire::test(PaymentClaims::class)->assertCountTableRecords(2);
    }

    // --- рахунок -------------------------------------------------------------

    public function test_an_invoice_is_only_an_expectation(): void
    {
        $order = $this->order();

        $invoice = \App\Models\Invoice::create([
            'number' => 'AF-1', 'sequence' => 1, 'order_id' => $order->id,
            'client_id' => $this->client->id, 'issued_on' => now(), 'amount' => 5000,
            'token' => str_repeat('a', 40),
        ]);

        $claim = $this->claims->recordInvoice($invoice, PaymentClaim::REPORTED_BY_AGENT);
        $again = $this->claims->recordInvoice($invoice, PaymentClaim::REPORTED_BY_AGENT);

        $this->assertSame($claim->id, $again->id, 'повторний рахунок — не друга заява');
        $this->assertFalse((bool) $order->fresh()->is_paid);

        // Очікування за рахунком — не «гроші є», у суму «чекають» не входить.
        $this->assertEquals(0, $this->claims->orderPaymentState($order->fresh())['pending_claims_amount']);

        // Переказ прийшов — рахунок більше не чекає.
        $this->claims->confirm($this->claim($order), $this->cash(), $this->manager);
        $this->assertSame(PaymentClaim::STATUS_SUPERSEDED, $claim->fresh()->status);
    }

    // --- запобіжник у моделі -------------------------------------------------

    public function test_a_payment_without_a_cash_box_is_refused(): void
    {
        $this->expectException(ValidationException::class);

        Transaction::create([
            'type' => 'income', 'category' => 'Оплата клієнта', 'amount' => 100,
            'date' => now(), 'order_id' => $this->order()->id,
        ]);
    }

    public function test_order_bookkeeping_entries_are_not_affected(): void
    {
        // «Нове замовлення» — теж income, але без order_id: це журнал нарахувань,
        // не гроші. Запобіжник його не чіпає, інакше жодне замовлення не створилось би.
        $this->actingAs($this->user('cook'));

        $this->order();

        $this->assertSame(1, Transaction::whereNull('order_id')->where('category', 'Нове замовлення')->count());
    }

    // --- API агента ----------------------------------------------------------

    public function test_the_agent_can_file_a_claim_but_not_pay(): void
    {
        Storage::fake('local');
        $order = $this->order();

        $this->postJson("/api/inbox/v1/orders/{$order->id}/payment-claims", [
            'source' => 'client_cash', 'amount' => 2600,
            'attachment' => UploadedFile::fake()->image('receipt.jpg'),
        ], $this->authHeaders())
            ->assertCreated()
            ->assertJsonPath('claim.status', 'pending')
            ->assertJsonPath('claim.method', 'cash')
            ->assertJsonPath('payment.is_paid', false)
            ->assertJsonPath('payment.pending_claims_amount', 2600);

        $this->assertFalse((bool) $order->fresh()->is_paid);

        $claim = PaymentClaim::first();
        $this->assertSame(PaymentClaim::REPORTED_BY_AGENT, $claim->reported_by_type);
        Storage::disk('local')->assertExists($claim->attachment_path);
    }

    public function test_the_agent_cannot_file_courier_or_invoice_claims(): void
    {
        $order = $this->order();

        foreach (['courier_cash', 'invoice_sent'] as $source) {
            $this->postJson("/api/inbox/v1/orders/{$order->id}/payment-claims",
                ['source' => $source, 'amount' => 100], $this->authHeaders())
                ->assertUnprocessable();
        }
    }

    public function test_the_payment_block_tells_the_agent_what_to_say(): void
    {
        $order = $this->order();
        $claim = $this->claim($order, amount: 2600);

        $this->getJson("/api/inbox/v1/orders/{$order->id}/payment", $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('payment.debt', 5000)
            ->assertJsonPath('payment.paid_amount', 0)
            ->assertJsonPath('payment.pending_claims_amount', 2600)
            ->assertJsonPath('payment.last_claim.status', 'pending');

        $this->claims->confirm($claim, $this->cash(), $this->manager);

        $this->getJson("/api/inbox/v1/orders/{$order->id}/payment", $this->authHeaders())
            ->assertJsonPath('payment.paid_amount', 2600)
            ->assertJsonPath('payment.debt', 2400)
            ->assertJsonPath('payment.pending_claims_amount', 0)
            ->assertJsonPath('payment.last_claim.status', 'confirmed');
    }

    public function test_the_client_orders_history_carries_the_payment_block(): void
    {
        $order = $this->order();
        $this->claim($order, amount: 1000);

        $this->getJson("/api/inbox/v1/clients/{$this->client->id}/orders", $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('data.0.payment.pending_claims_amount', 1000);
    }

    public function test_the_agent_needs_the_service_token(): void
    {
        $order = $this->order();

        $this->postJson("/api/inbox/v1/orders/{$order->id}/payment-claims", ['source' => 'client_transfer', 'amount' => 1])
            ->assertUnauthorized();
    }

    // --- вебхуки -------------------------------------------------------------

    public function test_the_agent_hears_about_confirmation_and_rejection(): void
    {
        $order     = $this->order();
        $confirmed = $this->claim($order, amount: 5000);
        $rejected  = $this->claim($order, amount: 999);

        $this->claims->confirm($confirmed, $this->cash(), $this->manager);
        $this->claims->reject($rejected, $this->manager, 'Не бачимо надходження');

        Queue::assertPushed(SendInboxWebhook::class, fn ($job) => $job->event === 'payment_claim.confirmed'
            && $job->payload['claim_id'] === $confirmed->id
            && $job->payload['order_is_paid'] === true);

        Queue::assertPushed(SendInboxWebhook::class, fn ($job) => $job->event === 'payment_claim.rejected'
            && $job->payload['reject_reason'] === 'Не бачимо надходження');

        // Чинний order.payment_received теж на місці.
        Queue::assertPushed(SendInboxWebhook::class, fn ($job) => $job->event === 'order.payment_received');
    }
}
