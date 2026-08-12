<?php

namespace Tests\Feature;

use App\Filament\Pages\Inbox;
use App\Models\Conversation;
use App\Models\MessengerAccount;
use App\Models\Order;
use App\Models\OrderDay;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Tests\Support\BuildsInboxTestSchema;
use Tests\TestCase;

/**
 * Конструктор замовлення в картці чату.
 *
 * Сенс усієї фічі: менеджер оформлює замовлення прямо з переписки і бачить ту
 * саму суму, що потім опиниться в CRM. Тому головна перевірка тут — сума з
 * картки дорівнює сумі створеного замовлення.
 */
class InboxOrderBuilderTest extends TestCase
{
    use BuildsInboxTestSchema;

    protected array $catalog;

    protected int $conversationId;

    protected int $clientId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildInboxSchema();
        $this->buildChatSchema();

        $this->catalog = $this->seedCatalog(pricePerDay: 898);
        $this->clientId = $this->makeClient(['target_kcal' => 1600]);

        $account = MessengerAccount::create([
            'channel'      => MessengerAccount::CHANNEL_TELEGRAM,
            'display_name' => 'A Food',
            'project'      => 'afood',
            'credentials'  => ['bot_token' => 'x'],
            'status'       => MessengerAccount::STATUS_ACTIVE,
        ]);

        $channelId = DB::table('client_channels')->insertGetId([
            'client_id' => $this->clientId, 'channel' => 'telegram', 'external_id' => '123',
        ]);

        $this->conversationId = Conversation::create([
            'client_channel_id'    => $channelId,
            'messenger_account_id' => $account->id,
            'channel'              => 'telegram',
            'external_chat_id'     => '123',
            'status'               => Conversation::STATUS_OPEN,
        ])->id;

        $user = new User(['name' => 'Менеджер', 'email' => 'm@test.local']);
        $user->role = 'admin';
        $user->password = bcrypt('secret');
        $user->save();

        $this->actingAs($user);
    }

    /** Таблиці чатів поверх базової схеми Inbox. */
    protected function buildChatSchema(): void
    {
        Schema::create('users', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('email')->unique();
            $t->string('password');
            $t->string('role')->default('admin');
            $t->rememberToken();
            $t->timestamps();
        });

        Schema::create('messenger_accounts', function (Blueprint $t) {
            $t->id();
            $t->string('channel');
            $t->string('display_name')->nullable();
            $t->string('project')->nullable();
            $t->string('external_account_id')->nullable();
            $t->text('credentials')->nullable();
            $t->string('status')->default('inactive');
            $t->text('last_error')->nullable();
            $t->timestamp('last_synced_at')->nullable();
            $t->unsignedBigInteger('connected_by_user_id')->nullable();
            $t->timestamps();
        });

        Schema::create('conversations', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('client_channel_id');
            $t->unsignedBigInteger('messenger_account_id');
            $t->string('channel');
            $t->string('external_chat_id')->nullable();
            $t->unsignedBigInteger('assigned_user_id')->nullable();
            $t->string('status')->default('open');
            $t->timestamp('last_message_at')->nullable();
            $t->string('last_message_preview')->nullable();
            $t->integer('unread_count')->default(0);
            $t->timestamp('closed_at')->nullable();
            $t->timestamps();
        });

        Schema::create('messages', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('conversation_id');
            $t->string('direction');
            $t->string('sender_type');
            $t->unsignedBigInteger('sender_user_id')->nullable();
            $t->string('type')->default('text');
            $t->text('text')->nullable();
            $t->string('external_message_id')->nullable();
            $t->unsignedBigInteger('reply_to_message_id')->nullable();
            $t->string('status')->nullable();
            $t->text('raw_payload')->nullable();
            $t->text('error_message')->nullable();
            $t->timestamp('sent_at')->nullable();
            $t->timestamp('delivered_at')->nullable();
            $t->timestamp('read_at')->nullable();
            $t->timestamps();
        });

        Schema::create('message_attachments', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('message_id');
            $t->string('file_url')->nullable();
            $t->string('file_name')->nullable();
            $t->string('mime_type')->nullable();
            $t->unsignedBigInteger('size_bytes')->nullable();
            $t->integer('duration_seconds')->nullable();
            $t->timestamps();
        });
    }

    protected function page()
    {
        return Livewire::test(Inbox::class)
            ->call('selectConversation', $this->conversationId);
    }

    public function test_opening_the_builder_takes_the_brand_from_the_messenger_account(): void
    {
        $this->page()
            ->call('openBuilder')
            ->assertSet('builderOpen', true)
            // Бренд підставився з акаунта — менеджер його не обирає.
            ->assertSet('builderProject', 'afood')
            // Калорійність — з картки клієнта.
            ->assertSet('builderCalories', 1600);
    }

    public function test_it_offers_only_tariffs_of_this_brand_that_have_prices(): void
    {
        DB::table('tariffs')->insert([
            ['name' => 'Без цін',   'project' => 'afood', 'is_active' => 1],
            ['name' => 'Чужий',     'project' => 'u_fit', 'is_active' => 1],
        ]);

        $component = $this->page()->call('openBuilder');
        $tariffs   = $component->instance()->builderTariffs();

        $this->assertCount(1, $tariffs);
        $this->assertSame($this->catalog['tariff_id'], $tariffs->first()->id);
    }

    public function test_it_shows_a_price_once_tariff_and_calories_are_chosen(): void
    {
        $component = $this->page()
            ->call('openBuilder')
            ->set('builderTariffId', $this->catalog['tariff_id'])
            ->set('builderCalories', 1600)
            ->set('builderDays', 5);

        $quote = $component->instance()->builderQuote();

        $this->assertSame(898.0, $quote['price_per_day']);
        $this->assertSame(4490.0, $quote['subtotal']);
        $this->assertSame(4490.0, $quote['total']);
    }

    public function test_a_discount_is_reflected_in_the_total(): void
    {
        $component = $this->page()
            ->call('openBuilder')
            ->set('builderTariffId', $this->catalog['tariff_id'])
            ->set('builderCalories', 1600)
            ->set('builderDays', 5)
            ->set('builderDiscount', 200);

        $quote = $component->instance()->builderQuote();

        $this->assertSame(200.0, $quote['discount']);
        $this->assertSame(4290.0, $quote['total']);
    }

    public function test_it_explains_why_a_price_cannot_be_calculated(): void
    {
        DB::table('tariffs')->where('id', $this->catalog['tariff_id'])->update(['min_days' => 21]);

        $component = $this->page()
            ->call('openBuilder')
            ->set('builderTariffId', $this->catalog['tariff_id'])
            ->set('builderCalories', 1600)
            ->set('builderDays', 5);

        $this->assertNull($component->instance()->builderQuote());
        $this->assertStringContainsString('від 21 днів', $component->instance()->builderError);
    }

    public function test_it_creates_the_order_with_the_price_the_manager_saw(): void
    {
        $component = $this->page()
            ->call('openBuilder')
            ->set('builderTariffId', $this->catalog['tariff_id'])
            ->set('builderCalories', 1600)
            ->set('builderDays', 5)
            ->set('builderDiscount', 200)
            ->set('builderStart', '2026-08-17')
            ->set('builderWindow', 'evening');

        $shown = $component->instance()->builderQuote()['total'];

        $component->call('createOrderFromChat')->assertSet('builderOpen', false);

        $order = Order::first();

        $this->assertNotNull($order);
        // Те, що бачив менеджер, і те, що лягло в CRM — одна сума.
        $this->assertSame($shown, (float) $order->final_price);
        $this->assertSame(4490.0, (float) $order->total_price);
        $this->assertSame('afood', $order->project);
        $this->assertSame('every_day_evening', $order->schedule_type);
        $this->assertSame('telegram_inbox', $order->source);
        $this->assertStringContainsString("діалог #{$this->conversationId}", $order->comment);

        // Дні мають бути — інакше замовлення не піде у виробництво.
        $this->assertSame(5, OrderDay::where('order_id', $order->id)->count());
    }

    public function test_it_refuses_to_create_an_order_it_cannot_price(): void
    {
        DB::table('tariff_prices')->where('tariff_id', $this->catalog['tariff_id'])->update(['price_per_day' => 0]);

        $this->page()
            ->call('openBuilder')
            ->set('builderTariffId', $this->catalog['tariff_id'])
            ->set('builderCalories', 1600)
            ->call('createOrderFromChat');

        $this->assertSame(0, Order::count());
        $this->assertSame(0, OrderDay::count());
    }

    public function test_switching_the_brand_resets_the_tariff(): void
    {
        $this->page()
            ->call('openBuilder')
            ->set('builderTariffId', $this->catalog['tariff_id'])
            ->set('builderProject', 'u_fit')
            ->assertSet('builderTariffId', null);
    }

    // --- адреса ------------------------------------------------------------

    public function test_it_prefills_the_address_from_the_client(): void
    {
        DB::table('client_addresses')->insert([
            'client_id'         => $this->clientId,
            'address'           => 'вул. Ахматової, 13Б',
            'address_apartment' => '88',
            'delivery_comment'  => "Домофон: 258\nПередача: залишити у консьєржа",
            'is_default'        => true,
        ]);

        $this->page()
            ->call('openBuilder')
            ->assertSet('builderAddress', 'вул. Ахматової, 13Б')
            ->assertSet('builderApartment', '88')
            // Домофон і спосіб передачі лежать у коментарі рядками — розбираємо назад.
            ->assertSet('builderIntercom', '258')
            ->assertSet('builderHandoff', 'залишити у консьєржа');
    }

    public function test_the_address_lands_on_every_day_and_is_saved_to_the_client(): void
    {
        $this->page()
            ->call('openBuilder')
            ->set('builderTariffId', $this->catalog['tariff_id'])
            ->set('builderCalories', 1600)
            ->set('builderDays', 2)
            ->set('builderStart', '2026-08-17')
            ->set('builderAddress', 'вул. Нова, 5')
            ->set('builderApartment', '12')
            ->set('builderIntercom', '777')
            ->set('builderHandoff', 'подзвонити знизу')
            ->call('createOrderFromChat');

        $days = OrderDay::where('order_id', Order::first()->id)->get();

        $this->assertCount(2, $days);
        foreach ($days as $day) {
            $this->assertSame('вул. Нова, 5', $day->address);
            $this->assertSame('12', $day->address_apartment);
            $this->assertStringContainsString('Домофон: 777', $day->delivery_comment);
        }

        // Наступного разу підставиться сама.
        $this->assertDatabaseHas('client_addresses', [
            'client_id' => $this->clientId, 'address' => 'вул. Нова, 5',
        ]);
    }

    // --- повтор замовлення -------------------------------------------------

    public function test_repeating_an_order_copies_its_parameters(): void
    {
        $component = $this->page()
            ->call('openBuilder')
            ->set('builderTariffId', $this->catalog['tariff_id'])
            ->set('builderCalories', 1600)
            ->set('builderDays', 7)
            ->set('builderStart', '2026-08-17')
            ->set('builderWindow', 'evening')
            ->call('createOrderFromChat');

        $first = Order::first();

        $component->call('repeatOrder', $first->id)
            ->assertSet('builderOpen', true)
            ->assertSet('builderTariffId', $this->catalog['tariff_id'])
            ->assertSet('builderCalories', 1600)
            ->assertSet('builderDays', 7)
            ->assertSet('builderWindow', 'evening')
            // Продовження починається з дня після завершення попереднього.
            ->assertSet('builderStart', '2026-08-24');
    }

    public function test_it_does_not_repeat_an_order_of_another_client(): void
    {
        $strangerId = $this->makeClient(['name' => 'Чужий', 'phone' => '0670000000']);

        $order = Order::create([
            'client_id' => $strangerId, 'project' => 'afood',
            'tariff_id' => $this->catalog['tariff_id'], 'calories' => 1600,
            'duration' => 3, 'start_date' => '2026-08-01', 'end_date' => '2026-08-03',
            'scale_factor' => 1.0,
        ]);

        $this->page()
            ->call('repeatOrder', $order->id)
            ->assertSet('builderOpen', false);
    }

    // --- матчинг контакту --------------------------------------------------

    public function test_it_finds_an_existing_client_by_phone_before_creating_one(): void
    {
        $this->unmatchContact();

        $this->page()
            ->call('openMatch')
            ->set('matchPhone', '+38 (095) 553-26-77')
            ->call('searchClient')
            ->assertSet('matchFound.id', $this->clientId);
    }

    public function test_linking_a_found_client_attaches_the_contact(): void
    {
        $this->unmatchContact();

        $this->page()
            ->call('openMatch')
            ->set('matchPhone', '0955532677')
            ->call('searchClient')
            ->call('linkFoundClient')
            ->assertSet('matchOpen', false);

        $this->assertDatabaseHas('client_channels', [
            'external_id' => '123', 'client_id' => $this->clientId,
        ]);
    }

    public function test_it_creates_a_new_client_when_none_was_found(): void
    {
        $this->unmatchContact();

        $this->page()
            ->call('openMatch')
            ->set('matchPhone', '0631112233')
            ->call('searchClient')
            ->assertSet('matchFound', null)
            ->set('matchName', 'Новий Клієнт')
            ->call('createClientFromChat')
            ->assertSet('matchOpen', false);

        $this->assertDatabaseHas('clients', [
            'name' => 'Новий Клієнт', 'phone' => '0631112233', 'sales_source' => 'telegram_inbox',
        ]);

        $created = DB::table('clients')->where('name', 'Новий Клієнт')->value('id');
        $this->assertDatabaseHas('client_channels', ['external_id' => '123', 'client_id' => $created]);
    }

    public function test_it_refuses_to_create_a_nameless_client(): void
    {
        $this->unmatchContact();

        $this->page()
            ->call('openMatch')
            ->set('matchName', '  ')
            ->call('createClientFromChat');

        $this->assertSame(1, DB::table('clients')->count());
    }

    // --- нагадування і оплата ---------------------------------------------

    public function test_a_reminder_lands_on_the_retention_board(): void
    {
        $order = $this->makeOrderFromChat();

        $this->page()->call('scheduleReminder', $order->id, '3');

        $call = DB::table('order_calls')->where('order_id', $order->id)->first();

        $this->assertNotNull($call);
        $this->assertSame('new', $call->status);
        $this->assertSame(
            now()->addDays(3)->toDateString(),
            \Carbon\Carbon::parse($call->next_call_at)->toDateString(),
        );
    }

    public function test_a_reminder_before_the_order_ends_uses_its_end_date(): void
    {
        $order = $this->makeOrderFromChat();

        $this->page()->call('scheduleReminder', $order->id, 'end');

        $call = DB::table('order_calls')->where('order_id', $order->id)->first();

        // Замовлення закінчується 2026-08-21 → нагадати за день.
        $this->assertSame('2026-08-20', \Carbon\Carbon::parse($call->next_call_at)->toDateString());
    }

    public function test_a_closed_retention_card_is_reopened_by_a_new_reminder(): void
    {
        $order = $this->makeOrderFromChat();

        DB::table('order_calls')->insert([
            'order_id' => $order->id, 'client_id' => $this->clientId, 'status' => 'refused',
        ]);

        $this->page()->call('scheduleReminder', $order->id, '1');

        // Інакше нагадування осіло б у прихованій колонці й ніхто б його не побачив.
        $this->assertSame('new', DB::table('order_calls')->where('order_id', $order->id)->value('status'));
        $this->assertSame(1, DB::table('order_calls')->where('order_id', $order->id)->count());
    }

    public function test_confirming_payment_records_income_and_marks_the_order_paid(): void
    {
        $order = $this->makeOrderFromChat();

        $this->assertFalse((bool) $order->is_paid);

        $this->page()->call('confirmPayment', $order->id);

        $this->assertTrue((bool) $order->refresh()->is_paid);
        $this->assertDatabaseHas('transactions', [
            'order_id' => $order->id, 'type' => 'income',
        ]);
    }

    public function test_a_payment_leaves_a_note_in_the_conversation(): void
    {
        $order = $this->makeOrderFromChat();

        $this->page()->call('confirmPayment', $order->id);

        $note = \App\Models\Message::where('conversation_id', $this->conversationId)
            ->where('sender_type', \App\Models\Message::SENDER_SYSTEM)
            ->first();

        $this->assertNotNull($note);
        $this->assertStringContainsString('Оплату отримано', $note->text);
        $this->assertStringContainsString((string) $order->id, $note->text);
    }

    public function test_it_ignores_actions_on_another_clients_order(): void
    {
        $strangerId = $this->makeClient(['name' => 'Чужий', 'phone' => '0670000000']);

        $order = Order::create([
            'client_id' => $strangerId, 'project' => 'afood',
            'tariff_id' => $this->catalog['tariff_id'], 'calories' => 1600,
            'duration' => 3, 'start_date' => '2026-08-01', 'end_date' => '2026-08-03',
            'scale_factor' => 1.0,
        ]);

        $this->page()->call('scheduleReminder', $order->id, '3');
        $this->page()->call('confirmPayment', $order->id);

        $this->assertSame(0, DB::table('order_calls')->count());
        $this->assertFalse((bool) $order->refresh()->is_paid);
    }

    /** Замовлення цього клієнта, оформлене через картку чату. */
    protected function makeOrderFromChat(): Order
    {
        $this->page()
            ->call('openBuilder')
            ->set('builderTariffId', $this->catalog['tariff_id'])
            ->set('builderCalories', 1600)
            ->set('builderDays', 5)
            ->set('builderStart', '2026-08-17')
            ->call('createOrderFromChat');

        return Order::latest('id')->first();
    }

    /** Відв'язує контакт від клієнта — імітує нового співрозмовника. */
    protected function unmatchContact(): void
    {
        DB::table('client_channels')->where('external_id', '123')->update(['client_id' => null]);
    }
}
