<?php

namespace Tests\Feature;

use App\Filament\Resources\AiProposalResource;
use App\Models\AiAgent;
use App\Models\AiProposal;
use App\Models\Dish;
use App\Models\Order;
use App\Models\OrderDayDish;
use App\Models\User;
use App\Services\Agents\ProposalDesk;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\Support\BuildsInboxTestSchema;
use Tests\TestCase;

/**
 * Пульт ШІ-агентів: агент пропонує → людина погоджує → CRM виконує.
 */
class AiAgentsTest extends AgentsTestCase
{
    public function test_a_wrong_token_is_refused_and_the_agent_reads_its_rules(): void
    {
        $this->withToken('nope')->getJson('/api/agents/v1/me')->assertStatus(401);

        $this->api('GET', 'me')->assertOk()
            ->assertJsonPath('slug', 'individual')
            ->assertJsonPath('instructions', 'Кухні — без грошей і без згадок ШІ.');
        $this->assertNotNull($this->agent->fresh()->last_seen_at);
        // У базі лише хеш токена.
        $this->assertNotSame($this->token, $this->agent->fresh()->token_hash);
    }

    public function test_a_broken_proposal_comes_back_with_reasons(): void
    {
        $bad = $this->menuPayload();
        $bad['days'][0]['meals'][1]['dish_ref'] = 'missing';
        $bad['days'][0]['meals'][0]['dish_id'] = 99999;

        $this->api('POST', 'proposals', ['type' => 'individual_week_menu', 'payload' => $bad])
            ->assertStatus(422)
            ->assertJsonFragment(['2026-10-12 meals[0]: страви #99999 немає']);

        $this->api('POST', 'proposals', ['type' => 'client_message', 'payload' => ['x' => 1]])->assertStatus(422);
        $this->assertSame(0, AiProposal::count());
    }

    public function test_kilograms_or_batch_grams_in_a_new_tech_card_are_refused(): void
    {
        $chicken = $this->ingredient('філе куряче');
        $other = Dish::create(['name' => 'Стара страва']);
        foreach ([140, 150, 160, 150] as $g) {
            DB::table('dish_ingredients')->insert(['dish_id' => $other->id, 'type' => 'product', 'ingredient_id' => $chicken, 'net_weight_g' => $g]);
        }

        $kg = $this->menuPayload();
        $kg['new_dishes'][0]['ingredients'][0]['net_weight_g'] = 0.15;
        $this->api('POST', 'proposals', ['type' => 'individual_week_menu', 'payload' => $kg])
            ->assertStatus(422)->assertJsonPath('errors.0', fn ($e) => str_contains($e, 'кілограми замість грамів'));

        $batch = $this->menuPayload();
        $batch['new_dishes'][0]['ingredients'][0]['net_weight_g'] = 1500;
        $this->api('POST', 'proposals', ['type' => 'individual_week_menu', 'payload' => $batch])
            ->assertStatus(422)->assertJsonPath('errors.0', fn ($e) => str_contains($e, 'у грамах на одну порцію'));

        $this->assertSame(0, AiProposal::count());
    }

    public function test_an_approved_menu_creates_dishes_and_the_personal_menu(): void
    {
        $this->user(7);
        // Старе персональне меню на цю дату — замінюємо.
        OrderDayDish::create(['order_id' => $this->order->id, 'date' => '2026-10-12', 'meal_type_id' => 99, 'dish_id' => 1]);

        $id = $this->api('POST', 'proposals', ['type' => 'individual_week_menu', 'title' => 'Меню 12–18.10', 'payload' => $this->menuPayload()])
            ->assertCreated()->json('id');

        $p = AiProposal::find($id);
        $this->assertSame('pending', $p->status);
        $this->assertSame($this->order->client_id, $p->client_id);
        // Кнопки пішли обом погоджувачам.
        Http::assertSent(fn ($r) => str_contains($r->url(), 'sendMessage') && $r['chat_id'] === '111' && str_contains(json_encode($r['reply_markup']), 'aip:ok:'.$id));
        Http::assertSent(fn ($r) => str_contains($r->url(), 'sendMessage') && $r['chat_id'] === '222');

        $answer = app(ProposalDesk::class)->approve($p, User::find(7));
        $this->assertStringContainsString('виконано', $answer);

        $p->refresh();
        $this->assertSame('applied', $p->status);
        $dish = Dish::where('name', 'Інд · Курка з булгуром (Гоголь)')->firstOrFail();
        $this->assertSame(1, DB::table('dish_ingredients')->where('dish_id', $dish->id)->where('type', 'product')->count());

        $rows = OrderDayDish::where('order_id', $this->order->id)->orderBy('date')->orderBy('meal_type_id')->get();
        $this->assertCount(3, $rows);   // старий рядок 12.10 прибрано
        $this->assertSame('без солі', $rows[1]->cooking_note);
        $this->assertSame(400, (int) $rows[2]->weight_grams);

        // Повторна подача тієї ж страви не плодить дублі.
        $id2 = $this->api('POST', 'proposals', ['type' => 'individual_week_menu', 'payload' => $this->menuPayload()])->json('id');
        app(ProposalDesk::class)->approve(AiProposal::find($id2), User::find(7));
        $this->assertSame(1, Dish::where('name', 'Інд · Курка з булгуром (Гоголь)')->count());
        $this->assertSame(['Інд · Курка з булгуром (Гоголь)'], AiProposal::find($id2)->apply_result['dishes_reused']);
    }

    public function test_telegram_buttons_work_only_for_approvers(): void
    {
        $this->user(7);
        $this->user(12, 'manager');
        $id = $this->api('POST', 'proposals', ['type' => 'individual_week_menu', 'payload' => $this->menuPayload()])->json('id');
        $desk = app(ProposalDesk::class);

        $this->assertStringContainsString('лише призначені', $desk->button('999', "aip:ok:{$id}"));
        $this->assertSame('pending', AiProposal::find($id)->status);

        // Менеджер з переліку погоджує кнопкою.
        $desk->button('222', "aip:ok:{$id}");
        $p = AiProposal::find($id);
        $this->assertSame('applied', $p->status);
        $this->assertSame(12, (int) $p->reviewed_by);
        Http::assertSent(fn ($r) => str_contains($r->url(), 'editMessageText') && str_contains($r['text'], 'Погоджено'));

        // Повторне натискання нічого не робить.
        $this->assertStringContainsString('Уже розглянуто', $desk->button('111', "aip:no:{$id}"));
    }

    public function test_the_agent_sees_requested_changes(): void
    {
        $this->user(7);
        $id = $this->api('POST', 'proposals', ['type' => 'individual_week_menu', 'payload' => $this->menuPayload()])->json('id');
        app(ProposalDesk::class)->reject(AiProposal::find($id), User::find(7), 'Замість булгуру — рис', true);

        $this->api('GET', 'proposals?status=changes_requested')->assertOk()
            ->assertJsonPath('0.id', $id)
            ->assertJsonPath('0.review_comment', 'Замість булгуру — рис');
        $this->assertSame(0, OrderDayDish::where('order_id', $this->order->id)->count());
    }

    public function test_tasks_reports_and_memory(): void
    {
        $task = $this->agent->tasks()->create(['title' => 'Меню на 19–25.10']);

        $this->api('GET', 'tasks')->assertOk()->assertJsonPath('0.title', 'Меню на 19–25.10');
        $this->api('PATCH', "tasks/{$task->id}", ['status' => 'in_progress'])->assertOk();
        $this->assertNotNull($task->fresh()->started_at);

        $this->api('PUT', 'memory/client:1925:week:2026-10-12', ['value' => ['status' => 'approved', 'missing' => []]])->assertOk();
        $this->api('GET', 'memory?prefix=client:1925')->assertOk()->assertJsonPath('0.value.status', 'approved');

        $this->api('POST', 'reports', ['title' => 'Тиждень 12–18.10', 'body' => '10 клієнтів, питання по Балкіну', 'task_id' => $task->id])->assertCreated();
        $this->assertSame($task->id, (int) $this->agent->reports()->first()->ai_agent_task_id);

        // Чужа задача — ні.
        $other = AiAgent::create(['slug' => 'other', 'name' => 'Інший']);
        $foreign = $other->tasks()->create(['title' => 'чуже']);
        $this->api('PATCH', "tasks/{$foreign->id}", ['status' => 'done'])->assertNotFound();
    }

    public function test_only_approvers_open_the_approvals(): void
    {
        $this->actingAs($this->user(12, 'manager'));
        $this->assertTrue(AiProposalResource::canAccess());

        $this->actingAs($this->user(13, 'manager'));
        $this->assertFalse(AiProposalResource::canAccess());

        $this->actingAs($this->user(1));   // інший адмін, не в переліку
        $this->assertFalse(AiProposalResource::canAccess());
    }

    // --- повідомлення клієнтам (контракт з Inbox 11.10) ----------------------

    protected function messageAgent(): string
    {
        $this->agent->update(['allowed_types' => ['individual_week_menu', 'client_message']]);
        config([
            'services.ai_agents.inbox_send_url' => 'https://inbox.test/api/integrations/crm/send',
            'services.ai_agents.inbox_send_secret' => 'shh',
        ]);
        \Illuminate\Support\Facades\Storage::fake('local');

        return 'client_message';
    }

    protected function messagePayload(array $over = []): array
    {
        return array_merge([
            'conversation_id' => 72575, 'crm_client_id' => $this->order->client_id, 'account' => 'Afood',
            'text' => "Добрий день! Меню на тиждень у вкладенні.", 'based_on_message_id' => 123456,
            'attachments' => [['kind' => 'photo', 'name' => 'меню 1.png', 'mime' => 'image/png', 'base64' => base64_encode('PNGDATA')]],
            'preview' => 'Гоголь Денис · Telegram',
        ], $over);
    }

    public function test_a_client_message_is_checked_and_files_are_kept_out_of_the_database(): void
    {
        $type = $this->messageAgent();

        $this->api('POST', 'proposals', ['type' => $type, 'payload' => $this->messagePayload(['text' => 'Привіт, [імʼя]!'])])
            ->assertStatus(422)->assertJsonFragment(['text: є незаповнена [заготовка]']);
        $noBase = $this->messagePayload(); unset($noBase['based_on_message_id']);
        $this->api('POST', 'proposals', ['type' => $type, 'payload' => $noBase])->assertStatus(422)
            ->assertJsonFragment(['based_on_message_id: обовʼязковий (0 — якщо клієнт ще не писав)']);
        $gif = $this->messagePayload(['attachments' => [['kind' => 'photo', 'name' => 'a.gif', 'mime' => 'image/gif', 'base64' => base64_encode('GIF')]]]);
        $this->api('POST', 'proposals', ['type' => $type, 'payload' => $gif])->assertStatus(422);

        $id = $this->api('POST', 'proposals', ['type' => $type, 'payload' => $this->messagePayload()])->assertCreated()->json('id');
        $p = AiProposal::find($id);

        $this->assertSame($this->order->client_id, $p->client_id);
        $this->assertArrayNotHasKey('base64', $p->payload['attachments'][0]);
        \Illuminate\Support\Facades\Storage::disk('local')->assertExists($p->payload['attachments'][0]['path']);
        // У Telegram погоджувач бачить сам текст.
        Http::assertSent(fn ($r) => str_contains($r->url(), 'sendMessage') && str_contains($r['text'], 'Меню на тиждень'));
    }

    public function test_an_approved_message_goes_to_inbox_signed_and_once(): void
    {
        $type = $this->messageAgent();
        $this->user(7);
        Http::fake([
            'inbox.test/*' => Http::response(['message_ids' => [9001, 9002]], 201),
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 55]]),
        ]);
        $id = $this->api('POST', 'proposals', ['type' => $type, 'payload' => $this->messagePayload()])->json('id');

        $answer = app(ProposalDesk::class)->approve(AiProposal::find($id), User::find(7));
        $this->assertStringContainsString('надіслано', $answer);

        Http::assertSent(function ($r) use ($id) {
            if (!str_contains($r->url(), 'inbox.test')) return false;
            $ts = $r->header('X-Inbox-Timestamp')[0];
            $ok = $r->header('X-Inbox-Signature')[0] === 'sha256=' . hash_hmac('sha256', $ts . '.' . $r->body(), 'shh');
            $b = json_decode($r->body(), true);

            return $ok && $r->header('Idempotency-Key')[0] === "proposal-{$id}"
                && $b['conversation_id'] === 72575 && $b['proposal_id'] === $id && $b['approved_by']['id'] === 7
                && $b['attachments'][0]['base64'] === base64_encode('PNGDATA') && !isset($b['force']);
        });

        $p = AiProposal::find($id);
        $this->assertSame('applied', $p->status);
        $this->assertSame([9001, 9002], $p->apply_result['message_ids']);
    }

    public function test_a_stale_message_waits_and_can_be_forced(): void
    {
        $type = $this->messageAgent();
        $this->user(7);
        Http::fake([
            'inbox.test/*' => Http::sequence()
                ->push(['code' => 'stale'], 409)
                ->push(['message_ids' => [9003]], 201),
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 55]]),
        ]);
        $id = $this->api('POST', 'proposals', ['type' => $type, 'payload' => $this->messagePayload()])->json('id');
        $desk = app(ProposalDesk::class);

        $desk->approve(AiProposal::find($id), User::find(7));
        $p = AiProposal::find($id);
        $this->assertSame('changes_requested', $p->status);
        $this->assertSame('stale', $p->apply_result['code']);

        // Звичайне погодження вдруге не проходить, лише «надіслати все одно».
        $this->assertStringContainsString('Уже розглянуто', $desk->approve($p, User::find(7)));
        $desk->approve($p->fresh(), User::find(7), force: true);

        $this->assertSame('applied', AiProposal::find($id)->status);
        Http::assertSent(fn ($r) => str_contains($r->url(), 'inbox.test') && (json_decode($r->body(), true)['force'] ?? false) === true);
    }

    public function test_telegram_rejection_is_retried_then_marked_failed(): void
    {
        $type = $this->messageAgent();
        $this->user(7);
        Http::fake([
            'inbox.test/*' => Http::response(['message' => 'Telegram down'], 502),
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 55]]),
        ]);
        $id = $this->api('POST', 'proposals', ['type' => $type, 'payload' => $this->messagePayload()])->json('id');

        app(ProposalDesk::class)->approve(AiProposal::find($id), User::find(7));

        $this->assertSame('failed', AiProposal::find($id)->status);
        Http::assertSentCount(3 + Http::recorded(fn ($r) => str_contains($r->url(), 'telegram'))->count());
    }

    public function test_nothing_is_sent_until_inbox_is_configured(): void
    {
        $type = $this->messageAgent();
        config(['services.ai_agents.inbox_send_url' => '']);
        $this->user(7);
        $id = $this->api('POST', 'proposals', ['type' => $type, 'payload' => $this->messagePayload()])->json('id');

        app(ProposalDesk::class)->approve(AiProposal::find($id), User::find(7));

        $p = AiProposal::find($id);
        $this->assertSame('failed', $p->status);
        $this->assertStringContainsString('не налаштована', $p->apply_result['error']);
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'inbox'));
    }

    // --- заміни в стравах циклу ---------------------------------------------

    public function test_approved_replacements_are_written_and_replace_mode_clears_old_ones(): void
    {
        $this->agent->update(['allowed_types' => ['order_replacements']]);
        $this->user(7);
        $dish = Dish::create(['name' => 'Крок мадам']);
        $other = Dish::create(['name' => 'Омлет']);
        $chicken = $this->ingredient('філе куряче');
        $salmon = $this->ingredient('Лосось з/м');
        DB::table('order_replacements')->insert(['order_id' => $this->order->id, 'dish_id' => $other->id, 'original_product_id' => $chicken]);

        $this->api('POST', 'proposals', ['type' => 'order_replacements', 'payload' => [
            'order_id' => $this->order->id, 'items' => [['dish_id' => 99999]],
        ]])->assertStatus(422);

        $id = $this->api('POST', 'proposals', ['type' => 'order_replacements', 'payload' => [
            'order_id' => $this->order->id, 'mode' => 'replace',
            'items' => [
                ['dish_id' => $dish->id, 'original_product_id' => $chicken, 'replacement_product_id' => $salmon, 'comment' => 'не їсть курку'],
                ['dish_id' => $other->id, 'replacement_dish_id' => $dish->id],
            ],
        ]])->assertCreated()->json('id');

        app(ProposalDesk::class)->approve(AiProposal::find($id), User::find(7));

        $rows = DB::table('order_replacements')->where('order_id', $this->order->id)->orderBy('dish_id')->get();
        $this->assertCount(2, $rows);
        $this->assertSame($salmon, (int) $rows[0]->replacement_product_id);
        $this->assertNull($rows[1]->original_product_id);
        $this->assertSame($dish->id, (int) $rows[1]->replacement_dish_id);
        $this->assertSame(['order_id' => $this->order->id, 'replacements' => 2, 'removed' => 1], AiProposal::find($id)->apply_result);
    }

    public function test_bulk_approval_runs_every_pending_proposal(): void
    {
        $this->actingAs($this->user(7));
        \Filament\Facades\Filament::setCurrentPanel(\Filament\Facades\Filament::getPanel('admin'));
        $ids = [];
        // Різні дати: нова версія на ті самі дати замінила б попередню.
        foreach (['2026-10-12', '2026-10-14'] as $date) {
            $ids[] = $this->api('POST', 'proposals', ['type' => 'individual_week_menu', 'payload' => $this->menuPayload([
                'days' => [['date' => $date, 'meals' => [['meal_type_id' => $this->lunch, 'dish_ref' => 'hohol_chicken']]]],
            ])])->json('id');
        }

        \Livewire\Livewire::test(\App\Filament\Resources\AiProposalResource\Pages\ListAiProposals::class)
            ->callTableBulkAction('approve', AiProposal::whereIn('id', $ids)->get());

        $this->assertSame(['applied', 'applied'], AiProposal::whereIn('id', $ids)->orderBy('id')->pluck('status')->all());
    }

    public function test_every_admin_screen_renders(): void
    {
        $this->actingAs($this->user(7));
        \Filament\Facades\Filament::setCurrentPanel(\Filament\Facades\Filament::getPanel('admin'));
        $id = $this->api('POST', 'proposals', ['type' => 'individual_week_menu', 'payload' => $this->menuPayload()])->json('id');
        $this->agent->tasks()->create(['title' => 'Задача', 'status' => 'new']);
        $this->agent->reports()->create(['title' => 'Звіт', 'body' => '**ок**', 'level' => 'warn']);
        $this->agent->memory()->create(['key' => 'order:1:week', 'value' => ['status' => 'approved']]);

        \Livewire\Livewire::test(\App\Filament\Resources\AiProposalResource\Pages\ListAiProposals::class)->assertOk()->assertSee('Чекає погодження');
        \Livewire\Livewire::test(\App\Filament\Resources\AiProposalResource\Pages\ViewAiProposal::class, ['record' => $id])->assertOk()->assertSee('Меню по днях');
        \Livewire\Livewire::test(\App\Filament\Resources\AiAgentResource\Pages\ListAiAgents::class)->assertOk();
        foreach ([
            \App\Filament\Resources\AiAgentResource\RelationManagers\TasksRelationManager::class => 'Задача',
            \App\Filament\Resources\AiAgentResource\RelationManagers\ReportsRelationManager::class => 'Звіт',
            \App\Filament\Resources\AiAgentResource\RelationManagers\MemoryRelationManager::class => 'order:1:week',
        ] as $rm => $see) {
            \Livewire\Livewire::test($rm, ['ownerRecord' => $this->agent, 'pageClass' => \App\Filament\Resources\AiAgentResource\Pages\EditAiAgent::class])
                ->assertOk()->assertSee($see);
        }
    }

    // --- чат, повтори, собівартість -----------------------------------------

    public function test_the_agent_writes_with_files_and_a_telegram_reply_reaches_it(): void
    {
        $this->user(7);
        \Illuminate\Support\Facades\Storage::fake('local');

        $this->api('POST', 'messages', ['text' => 'Меню на тиждень у PDF', 'files' => [
            ['name' => 'меню.pdf', 'mime' => 'application/pdf', 'base64' => base64_encode('%PDF-1.4 test')],
        ]])->assertCreated();

        Http::assertSent(fn ($r) => str_contains($r->url(), 'sendMessage') && str_contains($r['text'], 'Меню на тиждень у PDF'));
        Http::assertSent(fn ($r) => str_contains($r->url(), 'sendDocument'));

        // Тарас відповідає реплаєм у Telegram на повідомлення агента (message_id 55).
        $this->postJson('/webhooks/telegram-bot', ['update_id' => 1, 'message' => [
            'message_id' => 77, 'chat' => ['id' => 111], 'from' => ['id' => 111],
            'text' => 'Поліні замість сирників — омлет', 'reply_to_message' => ['message_id' => 55],
        ]], ['X-Telegram-Bot-Api-Secret-Token' => (string) config('services.telegram.webhook_secret')]);

        $this->api('GET', 'messages?only_new_for_me=1')->assertOk()
            ->assertJsonFragment(['text' => 'Поліні замість сирників — омлет', 'from' => 'u7']);
    }

    public function test_agents_talk_to_each_other_without_bothering_people(): void
    {
        $reviewer = AiAgent::create(['slug' => 'reviewer', 'name' => 'Рецензент меню']);
        $reviewerToken = $reviewer->issueToken();
        Http::fake(['*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]])]);

        $this->api('POST', 'messages', ['to' => 'reviewer', 'text' => 'Глянь чернетку Гоголя'])->assertCreated();
        Http::assertNothingSent();

        $this->withToken($reviewerToken)->getJson('/api/agents/v1/messages?only_new_for_me=1')->assertOk()
            ->assertJsonPath('0.text', 'Глянь чернетку Гоголя')->assertJsonPath('0.from', 'individual');
        $this->withToken($reviewerToken)->postJson('/api/agents/v1/messages', ['to' => 'individual', 'text' => 'Курка тричі за тиждень'])->assertCreated();

        $this->api('GET', 'messages?only_new_for_me=1')->assertJsonFragment(['text' => 'Курка тричі за тиждень', 'from' => 'reviewer']);
    }

    public function test_repeats_within_four_weeks_are_flagged_unless_the_client_allows_them(): void
    {
        $payload = $this->menuPayload();
        $omelet = $payload['days'][0]['meals'][0]['dish_id'];
        OrderDayDish::create(['order_id' => $this->order->id, 'date' => '2026-09-30', 'meal_type_id' => $this->breakfast, 'dish_id' => $omelet]);
        OrderDayDish::create(['order_id' => $this->order->id, 'date' => '2026-09-01', 'meal_type_id' => $this->breakfast, 'dish_id' => $omelet]); // понад 28 днів — не рахуємо

        $res = $this->api('POST', 'proposals', ['type' => 'individual_week_menu', 'payload' => $payload])->assertCreated();
        $warnings = implode("\n", $res->json('warnings'));
        $this->assertStringContainsString('Повтор: Омлет циклу — вже була 30.09', $warnings);
        $this->assertStringNotContainsString('01.09', $warnings);
        $this->assertStringContainsString('Курка з булгуром (Гоголь) — у цьому меню 2 рази', $warnings);
        $this->assertStringContainsString('Повтор: Омлет', AiProposal::find($res->json('id'))->summary);

        $ok = $this->menuPayload(['repeat_ok' => [$omelet, 'hohol_chicken']]);
        $this->assertSame([], array_values(array_filter(
            $this->api('POST', 'proposals', ['type' => 'individual_week_menu', 'payload' => $ok])->json('warnings'),
            fn ($w) => str_starts_with($w, 'Повтор'),
        )));
    }

    public function test_food_cost_over_thirty_percent_is_flagged_at_once(): void
    {
        Schema::table('ingredients', function (Blueprint $t) {
            foreach (['price_per_kg', 'yield_percent'] as $c) {
                if (!Schema::hasColumn('ingredients', $c)) $t->float($c)->nullable();
            }
            if (!Schema::hasColumn('ingredients', 'unit')) $t->string('unit')->nullable();
        });
        // Лосось: 1000 грн/кг, 20 г білка → 80 ккал на 100 г.
        $salmon = DB::table('ingredients')->insertGetId(['name' => 'Лосось', 'unit' => 'кг', 'price_per_kg' => 1000, 'yield_percent' => 100, 'proteins_100g' => 20, 'fats_100g' => 0, 'carbs_100g' => 0]);
        $this->order->forceFill(['price_per_day' => 1100])->saveQuietly();
        \App\Models\Ingredient::clearAveragePriceCache();

        $payload = [
            'order_id' => $this->order->id,
            'new_dishes' => [['ref' => 's', 'name' => 'Інд · Лосось', 'base_weight_g' => 200, 'ingredients' => [['ingredient_id' => $salmon, 'net_weight_g' => 200]]]],
            // Фіксована вага 400 г → 400 г лосося = 400 грн = 36% від 1100.
            'days' => [['date' => '2026-10-12', 'meals' => [['meal_type_id' => $this->lunch, 'dish_ref' => 's', 'weight_grams' => 400]]]],
        ];

        $costs = app(\App\Services\Agents\IndividualWeekMenu::class)->costs($payload);
        $this->assertEqualsWithDelta(400, $costs[0]['cost'], 1);
        $this->assertEqualsWithDelta(36.4, $costs[0]['pct'], 0.2);

        $warnings = implode("\n", $this->api('POST', 'proposals', ['type' => 'individual_week_menu', 'payload' => $payload])->json('warnings'));
        $this->assertStringContainsString('Собівартість 12.10 ≈ 400 грн = 36.4% ціни дня (1100), норма ≤ 30% (≤ 330 грн)', $warnings);
    }

    public function test_the_chat_page_shows_the_conversation_and_approves_from_it(): void
    {
        $this->actingAs($this->user(7));
        \Filament\Facades\Filament::setCurrentPanel(\Filament\Facades\Filament::getPanel('admin'));
        $id = $this->api('POST', 'proposals', ['type' => 'individual_week_menu', 'title' => 'Меню Гоголя', 'payload' => $this->menuPayload()])->json('id');

        \Livewire\Livewire::test(\App\Filament\Pages\AiChat::class)
            ->assertSee('Меню Гоголя')->assertSee('Погодити')
            ->set('draft', 'Дякую, ок')->call('send')
            ->call('approve', $id)
            ->assertSee('Дякую, ок');

        $this->assertSame('applied', AiProposal::find($id)->status);
        $this->assertTrue(\App\Models\AiMessage::where('direction', 'system')->where('text', 'like', '%Погоджено%')->exists());
    }

    // --- зібрати з готового, навантаження кухні, передача на розсилку --------

    public function test_the_agent_finds_existing_dishes_with_client_conflicts(): void
    {
        $nuts = $this->ingredient('горіх волоський');
        $chicken = $this->ingredient('філе куряче');
        $cycle = Dish::create(['name' => 'Салат з куркою']);
        DB::table('dish_ingredients')->insert(['dish_id' => $cycle->id, 'type' => 'product', 'ingredient_id' => $chicken, 'net_weight_g' => 100]);
        DB::table('daily_menu_dishes')->insert(['dish_id' => $cycle->id]);
        $ind = Dish::create(['name' => 'Інд · Салат з куркою і горіхами']);
        DB::table('dish_ingredients')->insert([
            ['dish_id' => $ind->id, 'type' => 'product', 'ingredient_id' => $chicken, 'net_weight_g' => 100],
            ['dish_id' => $ind->id, 'type' => 'product', 'ingredient_id' => $nuts, 'net_weight_g' => 20],
        ]);
        $relation = $this->clientExclusionRelation();
        if ($relation) DB::table($relation[0])->insert(['client_id' => $this->order->client_id, $relation[1] => $nuts]);
        OrderDayDish::create(['order_id' => $this->order->id, 'date' => '2026-10-05', 'meal_type_id' => $this->lunch, 'dish_id' => $ind->id]);

        $res = collect($this->api('GET', "dishes?q=Салат&order_id={$this->order->id}")->assertOk()->json())->keyBy('name');

        $this->assertSame('cycle', $res['Салат з куркою']['kind']);
        $this->assertSame('individual', $res['Інд · Салат з куркою і горіхами']['kind']);
        $this->assertSame(['2026-10-05'], $res['Інд · Салат з куркою і горіхами']['served_to_client_last_28d']);
        if ($relation) $this->assertSame(['горіх волоський'], $res['Інд · Салат з куркою і горіхами']['conflicts']);
        $this->assertSame(['Салат з куркою'], collect($this->api('GET', 'dishes?kind=cycle')->json())->pluck('name')->all());
    }

    /** Таблиця виключень продуктів клієнта — як її називає модель. */
    protected function clientExclusionRelation(): ?array
    {
        $rel = (new \App\Models\Client)->ingredientExclusions();

        return Schema::hasTable($rel->getTable()) ? [$rel->getTable(), $rel->getRelatedPivotKeyName()] : null;
    }

    public function test_too_many_off_cycle_dishes_a_day_is_flagged(): void
    {
        config(['services.ai_agents.kitchen_offcycle_max' => 2]);
        $other = Order::create(['client_id' => $this->makeClient(['name' => 'Інший', 'phone' => '0670000001']), 'project' => 'u_fit',
            'tariff_id' => $this->order->tariff_id, 'calories' => 1600, 'duration' => 7, 'start_date' => '2026-10-12', 'end_date' => '2026-10-18', 'scale_factor' => 1]);
        foreach (['Інд · А', 'Інд · Б'] as $n) {
            OrderDayDish::create(['order_id' => $other->id, 'date' => '2026-10-12', 'meal_type_id' => $this->lunch, 'dish_id' => Dish::create(['name' => $n])->id]);
        }

        $warnings = implode("\n", $this->api('POST', 'proposals', ['type' => 'individual_week_menu', 'payload' => $this->menuPayload()])->json('warnings'));

        $this->assertStringContainsString('Навантаження кухні 12.10: 4 окремих страв поза циклом (ліміт 2)', $warnings);
        $this->assertStringNotContainsString('Навантаження кухні 13.10', $warnings);
    }

    public function test_an_approved_menu_is_handed_to_the_messaging_agent_with_its_files(): void
    {
        $this->user(7);
        \Illuminate\Support\Facades\Storage::fake('local');
        $inbox = AiAgent::create(['slug' => 'inbox', 'name' => 'Inbox']);
        $inboxToken = $inbox->issueToken();

        $id = $this->api('POST', 'proposals', ['type' => 'individual_week_menu', 'payload' => $this->menuPayload(),
            'files' => [['name' => 'Меню Гоголь.pdf', 'mime' => 'application/pdf', 'base64' => base64_encode('%PDF menu')]]])->json('id');
        app(ProposalDesk::class)->approve(AiProposal::find($id), User::find(7));

        $task = $inbox->tasks()->firstOrFail();
        $this->assertStringContainsString('Надіслати меню клієнту: Гоголь Денис (12.10–13.10)', $task->title);
        $file = $task->payload['files'][0];
        $this->assertSame('Меню Гоголь.pdf', $file['name']);

        $this->withToken($inboxToken)->get("/api/agents/v1/files/{$file['message_id']}/{$file['index']}")
            ->assertOk()->assertStreamedContent('%PDF menu');
        // Чужий агент файл не отримає.
        $stranger = AiAgent::create(['slug' => 'x', 'name' => 'X'])->issueToken();
        $this->withToken($stranger)->get("/api/agents/v1/files/{$file['message_id']}/{$file['index']}")->assertForbidden();
    }
}
