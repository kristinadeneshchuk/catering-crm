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
}
