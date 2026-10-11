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
class AiAgentsTest extends TestCase
{
    use BuildsInboxTestSchema;

    protected AiAgent $agent;
    protected string $token;
    protected Order $order;
    protected int $breakfast;
    protected int $lunch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(\Carbon\Carbon::parse('2026-10-11 12:00:00'));

        $this->buildInboxSchema();
        Schema::hasTable('users') || Schema::create('users', function (Blueprint $t) {
            $t->id(); $t->string('name'); $t->string('email'); $t->string('password'); $t->string('role')->nullable();
            $t->rememberToken(); $t->timestamps();
        });
        Schema::table('dishes', function (Blueprint $t) {
            $t->text('description')->nullable(); $t->float('base_weight_g')->nullable(); $t->string('group')->nullable();
        });
        Schema::create('meal_types', function (Blueprint $t) {
            $t->id(); $t->string('name'); $t->integer('sort_order')->default(1); $t->timestamps();
        });
        Schema::create('dish_ingredients', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('dish_id'); $t->string('type')->nullable();
            $t->unsignedBigInteger('ingredient_id')->nullable(); $t->unsignedBigInteger('child_dish_id')->nullable();
            $t->float('net_weight_g')->default(0);
        });
        Schema::create('order_day_dishes', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('order_id'); $t->date('date'); $t->unsignedBigInteger('meal_type_id');
            $t->unsignedBigInteger('dish_id'); $t->integer('weight_grams')->nullable(); $t->text('cooking_note')->nullable(); $t->timestamps();
        });
        (require database_path('migrations/2026_10_11_100000_create_ai_agents.php'))->up();

        config([
            'services.telegram.bot_token' => 'test-bot',
            'services.finance.super_admin_ids' => '7',
            'services.ai_agents.approvers' => '7:111,12:222',
        ]);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 55]])]);

        $this->breakfast = DB::table('meal_types')->insertGetId(['name' => 'Сніданок', 'sort_order' => 1]);
        $this->lunch = DB::table('meal_types')->insertGetId(['name' => 'Обід', 'sort_order' => 3]);

        $catalog = $this->seedCatalog(pricePerDay: 900);
        $this->order = Order::create([
            'client_id' => $this->makeClient(['name' => 'Гоголь Денис']), 'project' => 'u_fit',
            'tariff_id' => $catalog['tariff_id'], 'calories' => 2300, 'duration' => 7,
            'start_date' => '2026-10-12', 'end_date' => '2026-10-18', 'scale_factor' => 1, 'menu_type' => 'individual',
        ]);

        $this->agent = AiAgent::create([
            'slug' => 'individual', 'name' => 'Індивідуальні раціони', 'role' => 'Меню індивідуальним клієнтам',
            'instructions' => 'Кухні — без грошей і без згадок ШІ.', 'allowed_types' => ['individual_week_menu'],
        ]);
        $this->token = $this->agent->issueToken();
    }

    protected function user(int $id, string $role = 'admin'): User
    {
        $u = new User(['name' => "u{$id}", 'email' => "u{$id}@t.local"]);
        $u->id = $id; $u->role = $role; $u->password = 'x'; $u->save();

        return $u;
    }

    protected function api(string $method, string $uri, array $data = [])
    {
        return $this->withToken($this->token)->json($method, "/api/agents/v1/{$uri}", $data);
    }

    protected function menuPayload(array $over = []): array
    {
        $existing = Dish::create(['name' => 'Омлет циклу']);

        return array_merge([
            'order_id' => $this->order->id,
            'new_dishes' => [[
                'ref' => 'hohol_chicken', 'name' => 'Інд · Курка з булгуром (Гоголь)', 'group' => 'Обіди', 'base_weight_g' => 350,
                'description' => "Технологія:\n1. Запекти курку",
                'ingredients' => [['ingredient_id' => $this->ingredient('філе куряче'), 'net_weight_g' => 150]],
            ]],
            'days' => [
                ['date' => '2026-10-12', 'meals' => [
                    ['meal_type_id' => $this->breakfast, 'dish_id' => $existing->id],
                    ['meal_type_id' => $this->lunch, 'dish_ref' => 'hohol_chicken', 'cooking_note' => 'без солі'],
                ]],
                ['date' => '2026-10-13', 'meals' => [
                    ['meal_type_id' => $this->lunch, 'dish_ref' => 'hohol_chicken', 'weight_grams' => 400],
                ]],
            ],
        ], $over);
    }

    protected function ingredient(string $name): int
    {
        return DB::table('ingredients')->where('name', $name)->value('id')
            ?? DB::table('ingredients')->insertGetId(['name' => $name]);
    }

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
}
