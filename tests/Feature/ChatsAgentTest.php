<?php

namespace Tests\Feature;

use App\Jobs\ProcessInboxChat;
use App\Models\AiAgent;
use App\Models\AiAgentTask;
use App\Models\AiProposal;
use App\Models\ClientDietProfile;
use App\Models\InboxEvent;
use App\Services\Agents\ChatTriage;
use App\Services\Agents\ProposalDesk;
use App\Services\Agents\Runtime\AgentLoop;
use App\Services\Agents\Runtime\ModelGateway;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\ScriptedGateway;

/**
 * Агент «Переписки»: вебхук Inbox → пачка → сортування → задача → пропозиції.
 * Inbox і модель — підмінені; чатів у CRM не зберігаємо.
 */
class ChatsAgentTest extends AgentsTestCase
{
    protected ScriptedGateway $gateway;
    protected int $client;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        (require database_path('migrations/2026_09_16_100000_create_ai_runs_table.php'))->up();
        (require database_path('migrations/2026_10_25_100000_create_inbox_events.php'))->up();
        $this->user(7);
        $this->client = $this->order->client_id;
        DB::table('clients')->where('id', $this->client)->update(['phone' => '+380 67 123 45 67']);
        $this->order->forceFill(['status' => 'active'])->saveQuietly();
        config([
            'services.ai_agents.inbox_events_secret' => 'evt-secret',
            'services.ai_agents.chats.enabled' => true,
            'services.ai_agents.chats.shadow' => true,
            'services.inbox.agent_token' => 'read-token',
            'services.inbox.agent_base_url' => 'https://inbox.test',
        ]);
        $this->gateway = new ScriptedGateway();
        $this->app->instance(ModelGateway::class, $this->gateway);
    }

    protected function fakeInbox(?array $messages = null): void
    {
        $messages ??= [
            ['id' => 100, 'direction' => 'out', 'text' => 'Доброго дня! Меню на тиждень надіслали', 'sent_at' => '2026-10-11T08:00:00Z'],
            ['id' => 101, 'direction' => 'in', 'text' => 'Дякую. І ще: я не їм горох, приберіть будь ласка', 'sent_at' => '2026-10-11T09:00:00Z'],
            ['id' => 102, 'direction' => 'in', 'text' => 'І з 15-го нас не буде, пауза до 17-го', 'sent_at' => '2026-10-11T09:01:00Z'],
        ];
        Http::fake([
            'inbox.test/api/agent/conversations/555/messages*' => Http::response(['messages' => $messages, 'has_more' => false]),
            'inbox.test/api/agent/conversations/555' => Http::response([
                'conversation' => ['id' => 555], 'account' => ['name' => 'U-Fit'], 'link' => 'https://inbox.test/?chat=555',
                'contact' => ['crm_client_id' => $this->client, 'kind' => 'client'],
            ]),
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]]),
        ]);
    }

    protected function event(array $over = []): array
    {
        return array_replace_recursive([
            'event_id' => 'evt_1', 'type' => 'message.received', 'occurred_at' => '2026-10-11T09:01:00Z',
            'data' => [
                'message' => ['id' => 102, 'direction' => 'in', 'sender_type' => 'client', 'text' => 'не має бути в БД'],
                'conversation' => ['id' => 555, 'chat_type' => 'private', 'tags' => []],
                'account' => ['project_code' => 'u-fit'],
                'contact' => ['crm_client_id' => $this->client, 'kind' => 'client'],
            ],
        ], $over);
    }

    protected function postEvent(array $event, ?string $secret = 'evt-secret')
    {
        $raw = json_encode($event, JSON_UNESCAPED_UNICODE);

        return $this->call('POST', '/api/agents/inbox-events', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_INBOX_SIGNATURE' => 'sha256=' . hash_hmac('sha256', $raw, (string) $secret),
        ], $raw);
    }

    public function test_the_webhook_checks_the_signature_dedupes_and_batches_the_chat(): void
    {
        Queue::fake();
        $this->fakeInbox();

        $this->postEvent($this->event(), 'wrong')->assertStatus(401);
        $this->postEvent($this->event())->assertOk();
        $this->postEvent($this->event())->assertOk()->assertJson(['duplicate' => true]);
        $this->postEvent($this->event(['event_id' => 'evt_2', 'data' => ['contact' => ['kind' => 'courier']]]))->assertOk();
        $this->postEvent($this->event(['event_id' => 'evt_3', 'data' => ['conversation' => ['tags' => ['не клієнт']]]]))->assertOk();

        $this->assertSame(['queued', 'skipped', 'skipped'], InboxEvent::orderBy('id')->pluck('status')->all());
        $this->assertSame('контакт: courier', InboxEvent::where('event_id', 'evt_2')->value('note'));
        $this->assertFalse(DB::table('inbox_events')->where('event_id', 'evt_1')->get()->toJson() !== '' && str_contains(DB::table('inbox_events')->get()->toJson(), 'не має бути'));
        Queue::assertPushed(ProcessInboxChat::class, 1);
    }

    public function test_triage_skips_small_talk_and_remembers_where_it_stopped(): void
    {
        $this->fakeInbox([['id' => 200, 'direction' => 'in', 'text' => 'Дякую, все смачно!', 'sent_at' => '2026-10-11T09:00:00Z']]);
        $this->gateway->script = ['{"action": false, "kinds": [], "why": "подяка"}'];

        $this->assertSame('нічого робити', app(ChatTriage::class)->run(555));
        $this->assertSame(0, AiAgentTask::count());
        $this->assertSame(200, (int) AiAgent::serverChats()->memory()->where('key', 'chat:555:last_message_id')->value('value'));
        // Нового немає — модель більше не викликаємо.
        $this->assertSame('нового немає', app(ChatTriage::class)->run(555));
        $this->assertSame(1, $this->gateway->calls);
    }

    public function test_the_agent_turns_the_chat_into_shadow_proposals(): void
    {
        Queue::fake();
        $this->fakeInbox();
        $peas = $this->ingredient('Горох с/м');
        $this->gateway->script = [
            '{"action": true, "kinds": ["exclusion", "day_change"], "why": "не їсть горох і пауза 15–17"}',
            [['chat_context', []], ['read_chat', []]],
            [['propose_exclusion', ['ingredient_ids' => [$peas], 'group_slugs' => ['горошок'], 'summary' => 'Клієнт не їсть горох', 'evidence' => ['message_id' => 101, 'quote' => 'я не їм горох, приберіть будь ласка']]],
             ['propose_day_change', ['order_id' => $this->order->id, 'action' => 'pause', 'dates' => ['2026-10-15', '2026-10-16', '2026-10-17'], 'summary' => 'Пауза 15–17.10', 'evidence' => ['message_id' => 102, 'quote' => 'з 15-го нас не буде, пауза до 17-го']]]],
            [['finish', ['summary' => 'Подано виключення й паузу']]],
        ];

        $result = app(ChatTriage::class)->run(555);
        $task = AiAgentTask::sole();
        $this->assertSame("задача #{$task->id}", $result);
        $this->assertSame(['chat_check', $this->client, $this->order->id, true, 555, [101, 102]],
            [$task->template, $task->client_id, $task->order_id, $task->payload['shadow'], $task->payload['conversation_id'], $task->payload['new_message_ids']]);

        app(AgentLoop::class)->run($task);

        $this->assertSame('done', $task->fresh()->status);
        // Модель бачила чат з позначками нового й контекст картки.
        $seen = json_encode($this->gateway->seen[2], JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString('[НОВЕ] #101', $seen);
        $this->assertStringNotContainsString('[НОВЕ] #100', $seen);
        $this->assertSame(['client_exclusion_add', 'order_day_change'], AiProposal::orderBy('id')->pluck('type')->all());
        $this->assertSame(['shadow', 'shadow'], AiProposal::orderBy('id')->pluck('status')->all());
        $this->assertStringContainsString('«я не їм горох, приберіть будь ласка»', AiProposal::first()->summary);
        // Тінь: у картці нічого не змінилось.
        $this->assertSame(0, DB::table('client_ingredient_exclusion')->count());
    }

    public function test_an_exclusion_lands_on_every_card_of_the_person(): void
    {
        $twin = DB::table('clients')->insertGetId(['name' => 'Гоголь Денис (дубль)', 'phone' => '0671234567']);
        DB::table('clients')->insertGetId(['name' => 'Інша людина', 'phone' => '0501112233']);
        $corn = $this->ingredient('крахмал кукурузный');

        app(\App\Services\Agents\ChatProposals::class, ['type' => 'client_exclusion_add'])->apply([
            'client_id' => $this->client, 'ingredient_ids' => [$corn], 'group_slugs' => ['кукурудза'], 'evidence' => ['message_id' => 1, 'quote' => 'x'],
        ]);

        $this->assertEqualsCanonicalizing([$this->client, $twin], DB::table('client_ingredient_exclusion')->where('ingredient_id', $corn)->pluck('client_id')->all());
        $this->assertSame(['кукурудза'], ClientDietProfile::where('client_id', $twin)->value('excluded_groups'));
    }

    public function test_approved_chat_proposals_do_what_they_say(): void
    {
        $this->fakeInbox();
        $agent = AiAgent::serverChats();
        $desk = app(ProposalDesk::class);
        $ev = ['message_id' => 9, 'quote' => 'нова адреса Хрещатик 1'];

        $contact = $desk->submit($agent, ['type' => 'client_contact_update', 'payload' => ['client_id' => $this->client, 'address' => 'Хрещатик, 1', 'address_floor' => '7', 'evidence' => $ev]])['proposal'];
        $day = $desk->submit($agent, ['type' => 'order_day_change', 'payload' => ['client_id' => $this->client, 'order_id' => $this->order->id, 'action' => 'pause', 'dates' => ['2026-10-15'], 'evidence' => $ev]])['proposal'];
        $complaint = $desk->submit($agent, ['type' => 'client_complaint', 'payload' => ['client_id' => $this->client, 'issue' => 'Котлета сира всередині', 'date' => '2026-10-10', 'evidence' => $ev]])['proposal'];
        $bad = $desk->submit($agent, ['type' => 'client_exclusion_add', 'payload' => ['client_id' => $this->client, 'group_slugs' => ['нема_такої'], 'evidence' => $ev]]);
        $this->assertStringContainsString('невідомі групи нема_такої', implode(' ', $bad['errors']));

        $owner = $this->user(8);
        $desk->approve($contact, $owner);
        $desk->approve($day, $owner);
        $desk->approve($complaint, $owner);

        $this->assertSame(['Хрещатик, 1', '7'], [DB::table('clients')->where('id', $this->client)->value('address'), DB::table('clients')->where('id', $this->client)->value('address_floor')]);
        // Паузу CRM сама не ставить — менеджер вносить у замовленні (sticky paused, три копії логіки статусу).
        $this->assertSame('failed', $day->fresh()->status);
        $this->assertStringContainsString('вносить менеджер', $day->fresh()->apply_result['error']);
        $this->assertSame('applied', $complaint->fresh()->status);
        $this->assertStringContainsString('Котлета сира всередині', DB::table('ai_agent_reports')->value('body'));
    }
}
