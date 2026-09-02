<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderDay;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsInboxTestSchema;
use Tests\TestCase;

/**
 * Наскрізна перевірка Inbox API: каталог → розрахунок → клієнт → замовлення.
 *
 * Головне, що тут перевіряється: сума з /quotes збігається з сумою, яку
 * порахував сам Order при створенні. Якщо ці дві формули колись розійдуться —
 * менеджер побачить у Telegram одну ціну, а в CRM буде інша.
 */
class InboxApiTest extends TestCase
{
    use BuildsInboxTestSchema;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.inbox.token', 'test-service-token');

        $this->buildInboxSchema();
    }

    // --- авторизація -------------------------------------------------------

    public function test_it_rejects_requests_without_token(): void
    {
        $this->getJson('/api/inbox/v1/projects')->assertStatus(401);
    }

    public function test_it_rejects_a_wrong_token(): void
    {
        $this->getJson('/api/inbox/v1/projects', ['Authorization' => 'Bearer nope'])
            ->assertStatus(401);
    }

    public function test_it_reports_when_the_token_is_not_configured(): void
    {
        config()->set('services.inbox.token', '');

        $this->getJson('/api/inbox/v1/projects', ['Authorization' => 'Bearer whatever'])
            ->assertStatus(503);
    }

    // --- каталог -----------------------------------------------------------

    public function test_it_lists_active_projects(): void
    {
        $this->seedCatalog('afood');
        DB::table('projects')->insert(['slug' => 'dead', 'name' => 'Закритий', 'is_active' => 0]);

        $this->getJson('/api/inbox/v1/projects', $this->authHeaders())
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.code', 'afood');
    }

    public function test_catalog_returns_only_calorie_ranges_that_have_a_price(): void
    {
        $ids = $this->seedCatalog();

        // Діапазон без ціни у матриці — у каталог потрапити не повинен.
        DB::table('calorie_ranges')->insert([
            'name' => 'Power 3400-3500 ккал', 'min_kcal' => 3399, 'max_kcal' => 3500,
        ]);

        $response = $this->getJson("/api/inbox/v1/projects/{$ids['project_id']}/catalog", $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('project.code', 'afood')
            ->assertJsonCount(1, 'tariffs');

        $this->assertSame(1, count($response->json('tariffs.0.calorie_ranges')));
        $this->assertEquals(1000, $response->json('tariffs.0.calorie_ranges.0.price_per_day'));
    }

    public function test_catalog_hides_a_tariff_with_an_empty_price_matrix(): void
    {
        $ids = $this->seedCatalog();

        DB::table('tariffs')->insert([
            'name' => 'Без цін', 'project' => 'afood', 'is_active' => 1,
        ]);

        $this->getJson("/api/inbox/v1/projects/{$ids['project_id']}/catalog", $this->authHeaders())
            ->assertOk()
            ->assertJsonCount(1, 'tariffs')
            ->assertJsonPath('tariffs.0.id', $ids['tariff_id']);
    }

    // --- розрахунок --------------------------------------------------------

    public function test_it_quotes_a_price(): void
    {
        $ids = $this->seedCatalog(pricePerDay: 898);

        $this->postJson('/api/inbox/v1/quotes', [
            'project_id' => $ids['project_id'],
            'tariff_id'  => $ids['tariff_id'],
            'calories'   => 1600,
            'days'       => 5,
        ], $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('price_per_day', 898)
            ->assertJsonPath('subtotal', 4490)
            ->assertJsonPath('discount', 0)
            ->assertJsonPath('total', 4490)
            ->assertJsonPath('currency', 'UAH')
            ->assertJsonPath('calorie_range.id', $ids['range_id']);
    }

    public function test_it_applies_a_flat_discount(): void
    {
        $ids = $this->seedCatalog(pricePerDay: 898);

        $this->postJson('/api/inbox/v1/quotes', [
            'project_id' => $ids['project_id'],
            'tariff_id'  => $ids['tariff_id'],
            'calories'   => 1600,
            'days'       => 5,
            'discount'   => 200,
        ], $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('discount', 200)
            ->assertJsonPath('total', 4290);
    }

    public function test_it_applies_a_percent_discount(): void
    {
        $ids = $this->seedCatalog(pricePerDay: 1000);

        $this->postJson('/api/inbox/v1/quotes', [
            'project_id' => $ids['project_id'],
            'tariff_id'  => $ids['tariff_id'],
            'calories'   => 1600,
            'days'       => 10,
            'discount'   => ['type' => 'percent', 'value' => 10],
        ], $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('discount', 1000)
            ->assertJsonPath('total', 9000);
    }

    public function test_a_discount_never_makes_the_total_negative(): void
    {
        $ids = $this->seedCatalog(pricePerDay: 100);

        $this->postJson('/api/inbox/v1/quotes', [
            'project_id' => $ids['project_id'],
            'tariff_id'  => $ids['tariff_id'],
            'calories'   => 1600,
            'days'       => 1,
            'discount'   => 5000,
        ], $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('total', 0);
    }

    public function test_it_refuses_to_quote_calories_outside_every_range(): void
    {
        $ids = $this->seedCatalog();

        $this->postJson('/api/inbox/v1/quotes', [
            'project_id' => $ids['project_id'],
            'tariff_id'  => $ids['tariff_id'],
            'calories'   => 2800,
            'days'       => 5,
        ], $this->authHeaders())
            ->assertStatus(422)
            ->assertJsonValidationErrors('calories');
    }

    public function test_it_refuses_to_quote_when_the_matrix_has_no_price(): void
    {
        $ids = $this->seedCatalog();

        DB::table('tariff_prices')->where('tariff_id', $ids['tariff_id'])->update(['price_per_day' => 0]);

        $this->postJson('/api/inbox/v1/quotes', [
            'project_id' => $ids['project_id'],
            'tariff_id'  => $ids['tariff_id'],
            'calories'   => 1600,
            'days'       => 5,
        ], $this->authHeaders())
            ->assertStatus(422)
            ->assertJsonValidationErrors('tariff_id');
    }

    public function test_it_refuses_a_tariff_from_another_brand(): void
    {
        $afood = $this->seedCatalog('afood');
        $ufit  = $this->seedCatalog('u_fit', 'Інший тариф');

        $this->postJson('/api/inbox/v1/quotes', [
            'project_id' => $afood['project_id'],
            'tariff_id'  => $ufit['tariff_id'],
            'calories'   => 1600,
            'days'       => 5,
        ], $this->authHeaders())
            ->assertStatus(422)
            ->assertJsonValidationErrors('tariff_id');
    }

    public function test_it_enforces_the_minimum_days_of_a_tariff(): void
    {
        $ids = $this->seedCatalog(minDays: 21);

        $this->postJson('/api/inbox/v1/quotes', [
            'project_id' => $ids['project_id'],
            'tariff_id'  => $ids['tariff_id'],
            'calories'   => 1600,
            'days'       => 5,
        ], $this->authHeaders())
            ->assertStatus(422)
            ->assertJsonValidationErrors('days');
    }

    // --- клієнти -----------------------------------------------------------

    public function test_it_finds_a_client_by_phone_in_any_format(): void
    {
        $this->makeClient(['phone' => '+38 (095) 553-26-77']);

        $this->getJson('/api/inbox/v1/clients/search?phone=0955532677', $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('found', true)
            ->assertJsonPath('client.phone', '+38 (095) 553-26-77');
    }

    public function test_it_reports_an_unknown_client(): void
    {
        $this->getJson('/api/inbox/v1/clients/search?phone=0000000000', $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('found', false);
    }

    public function test_search_requires_a_phone_or_a_telegram_id(): void
    {
        $this->getJson('/api/inbox/v1/clients/search', $this->authHeaders())
            ->assertStatus(422);
    }

    public function test_it_finds_a_client_by_telegram_id(): void
    {
        $clientId = $this->makeClient();
        DB::table('client_channels')->insert([
            'client_id' => $clientId, 'channel' => 'telegram', 'external_id' => '123456789',
        ]);

        $this->getJson('/api/inbox/v1/clients/by-channel/telegram/123456789', $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('found', true)
            ->assertJsonPath('client.id', $clientId)
            ->assertJsonPath('client.telegram_user_id', '123456789');
    }

    public function test_it_creates_a_client_with_an_address_and_a_channel(): void
    {
        $ids = $this->seedCatalog();

        $response = $this->postJson('/api/inbox/v1/clients', [
            'project_id'       => $ids['project_id'],
            'full_name'        => 'Міжно Аркадій Сергійович',
            'phone'            => '0955532677',
            'telegram_user_id' => '123456789',
            'telegram_username' => 'arkadiy',
            'address' => [
                'address'   => 'вул. Ахматової, 13Б',
                'entrance'  => '1',
                'apartment' => '88',
                'intercom'  => '258',
                'handoff'   => 'Залишити у консьєржа',
            ],
        ], $this->authHeaders())->assertStatus(201);

        $clientId = $response->json('client.id');

        $this->assertDatabaseHas('clients', ['id' => $clientId, 'name' => 'Міжно Аркадій Сергійович']);
        $this->assertDatabaseHas('client_channels', [
            'client_id' => $clientId, 'channel' => 'telegram', 'external_id' => '123456789', 'project' => 'afood',
        ]);

        // Домофон і спосіб передачі окремих полів не мають — складаємо коментар.
        $address = DB::table('client_addresses')->where('client_id', $clientId)->first();
        $this->assertSame('вул. Ахматової, 13Б', $address->address);
        $this->assertStringContainsString('Домофон: 258', $address->delivery_comment);
        $this->assertStringContainsString('Передача: Залишити у консьєржа', $address->delivery_comment);
    }

    public function test_it_reuses_an_existing_client_instead_of_duplicating(): void
    {
        $existing = $this->makeClient(['phone' => '0955532677']);

        $this->postJson('/api/inbox/v1/clients', [
            'full_name' => 'Той самий',
            'phone'     => '0955532677',
        ], $this->authHeaders())
            ->assertStatus(201)
            ->assertJsonPath('client.id', $existing);

        $this->assertSame(1, DB::table('clients')->count());
    }

    public function test_it_attaches_a_telegram_channel_to_a_known_client(): void
    {
        $clientId = $this->makeClient();

        $this->postJson("/api/inbox/v1/clients/{$clientId}/channels", [
            'channel_type'     => 'telegram',
            'external_user_id' => '987654321',
            'username'         => 'arkadiy',
        ], $this->authHeaders())->assertStatus(201);

        $this->assertDatabaseHas('client_channels', [
            'client_id' => $clientId, 'external_id' => '987654321',
        ]);
    }

    // --- замовлення --------------------------------------------------------

    public function test_it_creates_an_order_with_days_and_a_matching_total(): void
    {
        $ids      = $this->seedCatalog(pricePerDay: 898);
        $clientId = $this->makeClient();

        $quote = $this->postJson('/api/inbox/v1/quotes', [
            'project_id' => $ids['project_id'],
            'tariff_id'  => $ids['tariff_id'],
            'calories'   => 1600,
            'days'       => 5,
            'discount'   => 200,
        ], $this->authHeaders())->assertOk();

        $response = $this->postJson('/api/inbox/v1/orders', [
            'project_id'      => $ids['project_id'],
            'client_id'       => $clientId,
            'tariff_id'       => $ids['tariff_id'],
            'calories'        => 1600,
            'days'            => 5,
            'start_date'      => '2026-08-17',
            'delivery_window' => 'morning',
            'discount'        => 200,
            'source'          => 'telegram_inbox',
        ], $this->authHeaders())->assertStatus(201);

        $orderId = $response->json('order.id');
        $order   = Order::find($orderId);

        // Ціна з /quotes і ціна, яку порахував сам Order, мають збігатись.
        $this->assertSame($quote->json('total'), $response->json('order.total'));
        $this->assertSame(4490.0, (float) $order->total_price);
        $this->assertSame(200.0, (float) $order->discount_amount);
        $this->assertSame(4290.0, (float) $order->final_price);

        // Дні мають бути створені — без них замовлення не піде у виробництво.
        $dates = OrderDay::where('order_id', $orderId)->orderBy('date')->pluck('date')
            ->map(fn ($d) => \Carbon\Carbon::parse($d)->toDateString())->all();

        $this->assertCount(5, $dates);
        $this->assertSame('2026-08-17', $dates[0]);
        $this->assertSame('2026-08-21', $dates[4]);

        $this->assertSame('every_day_morning', $order->schedule_type);
        $this->assertSame('afood', $order->project);
        $this->assertStringContainsString('Telegram Inbox', $order->comment);
    }

    public function test_it_creates_an_order_from_an_explicit_list_of_days(): void
    {
        $ids      = $this->seedCatalog(pricePerDay: 1000);
        $clientId = $this->makeClient();

        $response = $this->postJson('/api/inbox/v1/orders', [
            'project_id'    => $ids['project_id'],
            'client_id'     => $clientId,
            'tariff_id'     => $ids['tariff_id'],
            'calories'      => 1600,
            'days'          => 3,
            'start_date'    => '2026-08-17',
            'delivery_days' => ['2026-08-17', '2026-08-19', '2026-08-21'],
        ], $this->authHeaders())->assertStatus(201);

        $orderId = $response->json('order.id');

        $this->assertSame(3, OrderDay::where('order_id', $orderId)->count());
        $this->assertSame('2026-08-21', Order::find($orderId)->end_date->toDateString());
        $this->assertEquals(3000, $response->json('order.total'));
    }

    public function test_it_does_not_create_an_order_when_the_price_is_unknown(): void
    {
        $ids      = $this->seedCatalog();
        $clientId = $this->makeClient();

        DB::table('tariff_prices')->where('tariff_id', $ids['tariff_id'])->update(['price_per_day' => 0]);

        $this->postJson('/api/inbox/v1/orders', [
            'project_id' => $ids['project_id'],
            'client_id'  => $clientId,
            'tariff_id'  => $ids['tariff_id'],
            'calories'   => 1600,
            'days'       => 5,
            'start_date' => '2026-08-17',
        ], $this->authHeaders())->assertStatus(422);

        $this->assertSame(0, Order::count());
        $this->assertSame(0, OrderDay::count());
    }

    public function test_it_stamps_the_address_on_every_day_of_the_order(): void
    {
        $ids      = $this->seedCatalog();
        $clientId = $this->makeClient();

        $response = $this->postJson('/api/inbox/v1/orders', [
            'project_id' => $ids['project_id'],
            'client_id'  => $clientId,
            'tariff_id'  => $ids['tariff_id'],
            'calories'   => 1600,
            'days'       => 2,
            'start_date' => '2026-08-17',
            'address'    => [
                'address'   => 'вул. Ахматової, 13Б',
                'apartment' => '88',
                'intercom'  => '258',
                'handoff'   => 'Залишити у консьєржа',
            ],
        ], $this->authHeaders())->assertStatus(201);

        $days = OrderDay::where('order_id', $response->json('order.id'))->get();

        $this->assertCount(2, $days);
        foreach ($days as $day) {
            $this->assertSame('вул. Ахматової, 13Б', $day->address);
            $this->assertSame('88', $day->address_apartment);
            $this->assertStringContainsString('Домофон: 258', $day->delivery_comment);
        }
    }

    public function test_it_returns_the_order_history_of_a_client(): void
    {
        $ids      = $this->seedCatalog(pricePerDay: 500);
        $clientId = $this->makeClient();

        $this->postJson('/api/inbox/v1/orders', [
            'project_id' => $ids['project_id'],
            'client_id'  => $clientId,
            'tariff_id'  => $ids['tariff_id'],
            'calories'   => 1600,
            'days'       => 4,
            'start_date' => '2026-08-17',
        ], $this->authHeaders())->assertStatus(201);

        $this->getJson("/api/inbox/v1/clients/{$clientId}/orders", $this->authHeaders())
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.calories', 1600)
            ->assertJsonPath('data.0.days', 4)
            ->assertJsonPath('data.0.total', 2000)
            ->assertJsonPath('data.0.payment_status', 'unpaid');
    }

    /**
     * Inbox бере вікно доставки з історії замовлень. Поки цих полів не було,
     * він підставляв «Ранкова» наосліп — і вечірні клієнти отримували
     * замовлення на ранок.
     */
    public function test_the_order_history_carries_the_delivery_window_days_and_address(): void
    {
        $ids      = $this->seedCatalog(pricePerDay: 500);
        $clientId = $this->makeClient([
            'address'           => 'вул. Хрещатик, 1',
            'address_entrance'  => '2',
            'address_apartment' => '15',
            'address_floor'     => '4',
            'delivery_comment'  => 'Залишити на ресепшені',
        ]);

        $this->postJson('/api/inbox/v1/orders', [
            'project_id'      => $ids['project_id'],
            'client_id'       => $clientId,
            'tariff_id'       => $ids['tariff_id'],
            'calories'        => 1600,
            'days'            => 2,
            'start_date'      => '2026-09-04',
            'delivery_window' => 'evening',
        ], $this->authHeaders())->assertStatus(201);

        $this->getJson("/api/inbox/v1/clients/{$clientId}/orders", $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('data.0.delivery_window', 'evening')
            ->assertJsonPath('data.0.delivery_days', ['2026-09-04', '2026-09-05'])
            ->assertJsonPath('data.0.address.address', 'вул. Хрещатик, 1')
            ->assertJsonPath('data.0.address.apartment', '15')
            ->assertJsonPath('data.0.address.handoff', 'Залишити на ресепшені')
            ->assertJsonPath('data.0.address.intercom', null);
    }

    /** Без вікна замовлення лишається ранковим — це поведінка за замовчуванням. */
    public function test_an_order_without_a_window_reads_back_as_morning(): void
    {
        $ids      = $this->seedCatalog(pricePerDay: 500);
        $clientId = $this->makeClient();

        $this->postJson('/api/inbox/v1/orders', [
            'project_id' => $ids['project_id'],
            'client_id'  => $clientId,
            'tariff_id'  => $ids['tariff_id'],
            'calories'   => 1600,
            'days'       => 1,
            'start_date' => '2026-09-04',
        ], $this->authHeaders())->assertStatus(201);

        $this->getJson("/api/inbox/v1/clients/{$clientId}/orders", $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('data.0.delivery_window', 'morning');
    }
}
