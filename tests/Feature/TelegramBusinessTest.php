<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\MessengerAccount;
use App\Services\Messenger\ChannelDriverManager;
use App\Services\Messenger\Telegram\TelegramChannelDriver;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Telegram Business: клієнти пишуть на живий акаунт бренду, бот лише «руки».
 *
 * Головні речі, які тут перевіряються:
 *  - повідомлення клієнта стає inbound-діалогом у CRM;
 *  - відповідь власника з телефону не плутається з питанням клієнта;
 *  - без business_connection_id відправка падає з людською причиною.
 */
class TelegramBusinessTest extends TestCase
{
    protected MessengerAccount $account;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildSchema();

        $this->account = MessengerAccount::create([
            'channel'      => MessengerAccount::CHANNEL_TELEGRAM,
            'display_name' => 'A Food',
            'credentials'  => ['bot_token' => '123:ABC', 'webhook_secret' => 'sekret'],
            'status'       => MessengerAccount::STATUS_INACTIVE,
        ]);
    }

    protected function buildSchema(): void
    {
        Schema::create('messenger_accounts', function (Blueprint $t) {
            $t->id();
            $t->string('channel');
            $t->string('display_name')->nullable();
            $t->string('external_account_id')->nullable();
            $t->text('credentials')->nullable();
            $t->string('status')->default('inactive');
            $t->text('last_error')->nullable();
            $t->timestamp('last_synced_at')->nullable();
            $t->unsignedBigInteger('connected_by_user_id')->nullable();
            $t->timestamps();
        });

        Schema::create('clients', function (Blueprint $t) {
            $t->id();
            $t->string('name')->nullable();
            $t->string('phone')->nullable();
            $t->string('telegram_username')->nullable();
            $t->string('instagram_url')->nullable();
            $t->decimal('balance', 12, 2)->default(0);
            $t->timestamps();
        });

        Schema::create('client_channels', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('client_id')->nullable();
            $t->string('channel');
            $t->string('project')->nullable();
            $t->string('external_id');
            $t->string('username')->nullable();
            $t->string('display_name')->nullable();
            $t->string('avatar_url')->nullable();
            $t->text('raw_meta')->nullable();
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

    protected function clientMessage(array $overrides = []): array
    {
        return ['business_message' => array_merge([
            'business_connection_id' => 'conn_1',
            'message_id' => 5001,
            'date'       => 1755000000,
            'from'       => ['id' => 123456789, 'first_name' => 'Аркадій', 'username' => 'arkadiy'],
            'chat'       => ['id' => 123456789, 'type' => 'private', 'first_name' => 'Аркадій'],
            'text'       => 'Скільки коштує 5 днів на 1600 ккал?',
        ], $overrides)];
    }

    protected function hook(array $payload, string $secret = 'sekret')
    {
        return $this->withHeaders(['X-Telegram-Bot-Api-Secret-Token' => $secret])
            ->postJson("/webhooks/telegram/{$this->account->id}", $payload);
    }

    // --- маршрутизація драйвера -------------------------------------------

    public function test_the_manager_now_resolves_a_telegram_driver(): void
    {
        $this->assertInstanceOf(
            TelegramChannelDriver::class,
            app(ChannelDriverManager::class)->for($this->account),
        );
    }

    // --- вхідні -----------------------------------------------------------

    public function test_a_client_message_creates_a_conversation(): void
    {
        $this->hook($this->clientMessage())->assertOk()->assertJson(['ok' => true]);

        $this->assertSame(1, Conversation::count());

        $message = Message::first();
        $this->assertSame(Message::DIRECTION_INBOUND, $message->direction);
        $this->assertSame('Скільки коштує 5 днів на 1600 ккал?', $message->text);

        $conversation = Conversation::first();
        $this->assertSame(1, $conversation->unread_count);
        $this->assertSame('123456789', $conversation->external_chat_id);

        // Контакт створився і чекає на матчинг із клієнтом CRM.
        $this->assertDatabaseHas('client_channels', [
            'channel' => 'telegram', 'external_id' => '123456789', 'username' => 'arkadiy',
        ]);
    }

    public function test_it_matches_a_known_client_by_telegram_username(): void
    {
        $clientId = DB::table('clients')->insertGetId([
            'name' => 'Аркадій', 'telegram_username' => 'arkadiy',
        ]);

        $this->hook($this->clientMessage())->assertOk();

        $this->assertDatabaseHas('client_channels', [
            'external_id' => '123456789', 'client_id' => $clientId,
        ]);
    }

    public function test_the_same_message_is_not_stored_twice(): void
    {
        $this->hook($this->clientMessage())->assertOk();
        $this->hook($this->clientMessage())->assertOk();

        $this->assertSame(1, Message::count());
    }

    public function test_it_understands_a_photo(): void
    {
        $this->hook($this->clientMessage([
            'text'    => null,
            'caption' => 'Ось моє меню',
            'photo'   => [
                ['file_id' => 'small', 'file_size' => 100],
                ['file_id' => 'big',   'file_size' => 900],
            ],
        ]))->assertOk();

        $message = Message::first();
        $this->assertSame(Message::TYPE_IMAGE, $message->type);
        $this->assertSame('Ось моє меню', $message->text);
    }

    // --- відповідь власника з телефону ------------------------------------

    public function test_an_owner_reply_from_the_phone_is_stored_as_outbound(): void
    {
        $this->account->update(['external_account_id' => '555000111']);

        // Спочатку питання клієнта.
        $this->hook($this->clientMessage())->assertOk();

        // Тепер власник відповів зі свого телефону, повз CRM.
        $this->hook($this->clientMessage([
            'message_id' => 5002,
            'from'       => ['id' => 555000111, 'first_name' => 'Менеджер'],
            'chat'       => ['id' => 123456789, 'type' => 'private', 'first_name' => 'Аркадій'],
            'text'       => '4490 грн, оформлюємо?',
        ]))->assertOk();

        $this->assertSame(2, Message::count());

        $reply = Message::where('external_message_id', '5002')->first();
        $this->assertSame(Message::DIRECTION_OUTBOUND, $reply->direction);
        $this->assertSame(Message::SENDER_USER, $reply->sender_type);
        $this->assertSame('4490 грн, оформлюємо?', $reply->text);

        // Обидва повідомлення в одному діалозі, і власна відповідь непрочитаною не рахується.
        $this->assertSame(1, Conversation::count());
        $this->assertSame(1, Conversation::first()->unread_count);
    }

    // --- підключення ------------------------------------------------------

    public function test_a_connection_update_activates_the_account(): void
    {
        $this->hook([
            'business_connection' => [
                'id'         => 'conn_abc',
                'user'       => ['id' => 555000111, 'first_name' => 'A Food'],
                'is_enabled' => true,
                'rights'     => ['can_reply' => true],
            ],
        ])->assertOk();

        $this->account->refresh();

        $this->assertSame(MessengerAccount::STATUS_ACTIVE, $this->account->status);
        $this->assertSame('conn_abc', $this->account->credentials['business_connection_id']);
        $this->assertSame('555000111', $this->account->external_account_id);
    }

    public function test_disabling_the_bot_deactivates_the_account(): void
    {
        $this->hook([
            'business_connection' => [
                'id' => 'conn_abc', 'user' => ['id' => 555000111], 'is_enabled' => false,
            ],
        ])->assertOk();

        $this->assertSame(MessengerAccount::STATUS_INACTIVE, $this->account->refresh()->status);
    }

    public function test_it_rejects_a_wrong_secret(): void
    {
        $this->hook($this->clientMessage(), 'wrong')->assertStatus(403);

        $this->assertSame(0, Message::count());
    }

    // --- відправка --------------------------------------------------------

    public function test_it_sends_a_reply_through_the_business_connection(): void
    {
        Http::fake(['*' => Http::response(['ok' => true, 'result' => ['message_id' => 777]])]);

        $this->account->update([
            'credentials' => $this->account->credentials + ['business_connection_id' => 'conn_abc'],
        ]);

        $message = $this->makeOutboundDraft();

        app(TelegramChannelDriver::class)->send($message);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/bot123:ABC/sendMessage')
                && $request['business_connection_id'] === 'conn_abc'
                && $request['chat_id'] === '123456789'
                && $request['text'] === 'Доброго дня!';
        });

        $message->refresh();
        $this->assertSame(Message::STATUS_SENT, $message->status);
        $this->assertSame('777', $message->external_message_id);
    }

    public function test_sending_fails_clearly_while_the_bot_is_not_added_to_the_business_account(): void
    {
        $message = $this->makeOutboundDraft();

        $this->expectExceptionMessageMatches('/Telegram Business/');

        app(TelegramChannelDriver::class)->send($message);
    }

    protected function makeOutboundDraft(): Message
    {
        $channelId = DB::table('client_channels')->insertGetId([
            'channel' => 'telegram', 'external_id' => '123456789',
        ]);

        $conversation = Conversation::create([
            'client_channel_id'    => $channelId,
            'messenger_account_id' => $this->account->id,
            'channel'              => 'telegram',
            'external_chat_id'     => '123456789',
            'status'               => Conversation::STATUS_OPEN,
        ]);

        return Message::create([
            'conversation_id' => $conversation->id,
            'direction'       => Message::DIRECTION_OUTBOUND,
            'sender_type'     => Message::SENDER_USER,
            'type'            => Message::TYPE_TEXT,
            'text'            => 'Доброго дня!',
            'status'          => Message::STATUS_PENDING,
        ]);
    }
}
