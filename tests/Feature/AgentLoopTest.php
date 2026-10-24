<?php

namespace Tests\Feature;

use App\Jobs\RunAgentTask;
use App\Models\AiAgent;
use App\Models\AiAgentTask;
use App\Models\AiProposal;
use App\Models\AiRun;
use App\Models\Dish;
use App\Services\Agents\Runtime\ModelGateway;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Агент «Меню» на сервері: модель → інструменти → пропозиція → погодження людиною.
 * Модель тут — сценарій кроків; перевіряємо цикл, тред, паузу й витрати.
 */
class AgentLoopTest extends AgentsTestCase
{
    protected AiAgent $menu;
    protected ScriptedGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        (require database_path('migrations/2026_09_16_100000_create_ai_runs_table.php'))->up();
        $this->user(7);
        $this->menu = AiAgent::create(['slug' => 'menu', 'name' => 'Меню', 'allowed_types' => ['individual_week_menu', 'order_replacements']]);
        $this->gateway = new ScriptedGateway();
        $this->app->instance(ModelGateway::class, $this->gateway);
    }

    protected function task(): AiAgentTask
    {
        return $this->menu->tasks()->create([
            'title' => 'Склади меню Гоголю на 14–15.10', 'status' => 'new', 'client_id' => $this->order->client_id,
            'order_id' => $this->order->id, 'period_from' => '2026-10-14', 'period_to' => '2026-10-15', 'template' => 'menu_period', 'created_by' => 7,
        ]);
    }

    public function test_the_agent_reads_the_card_checks_and_submits_a_menu_for_approval(): void
    {
        $dish = Dish::create(['name' => 'Омлет з овочами'])->id;
        DB::table('dish_ingredients')->insert(['dish_id' => $dish, 'type' => 'product', 'ingredient_id' => $this->ingredient('яйце'), 'net_weight_g' => 150]);
        $days = [['date' => '2026-10-14', 'meals' => [['meal_type_id' => $this->lunch, 'dish_id' => $dish]]]];
        $this->gateway->script = [
            [['task_context', []]],
            [['progress', ['text' => 'Збираю меню з циклу']], ['check_menu', ['days' => $days]]],
            [['submit_menu', ['summary' => 'Омлет на обід 14.10', 'payload' => ['days' => $days]]]],
            [['finish', ['summary' => 'Меню на 14.10 подано']]],
        ];
        $task = $this->task();

        RunAgentTask::dispatchSync($task);

        $task->refresh();
        $this->assertSame('done', $task->status);
        $this->assertSame(4, $task->steps);
        $proposal = AiProposal::sole();
        $this->assertSame([$task->id, 'pending', $this->order->id], [$proposal->ai_agent_task_id, $proposal->status, $proposal->order_id]);
        $this->assertSame(['Взяв задачу в роботу', 'Читаю картку клієнта, замовлення й виключення', 'Збираю меню з циклу', 'Перевіряю чернетку меню (1 дн.)'],
            $task->events()->whereIn('kind', ['step', 'tool'])->where('text', '!=', 'progress')->limit(4)->pluck('text')->all());
        $this->assertTrue($task->events()->where('ai_proposal_id', $proposal->id)->exists());
        // Модель отримала контекст: дні доставки й цілі замовлення.
        $context = json_decode($this->gateway->toolResult(1, 0), true);
        $this->assertSame(['2026-10-14', '2026-10-15'], $context['delivery_days']);
        $this->assertSame(2300, $context['order']['targets']['kcal']);
        // Витрати: 4 кроки по 1000 вхідних + 200 вихідних на Opus 5.5 = 4 × $0.008.
        $this->assertSame(4, AiRun::where('purpose', 'agent_menu')->count());
        $this->assertEqualsWithDelta(0.032, (float) $task->cost_usd, 0.0001);
    }

    public function test_dish_tools_show_the_full_composition_and_conflicts(): void
    {
        $starch = $this->ingredient('крохмаль кукурудзяний');
        DB::table('client_ingredient_exclusion')->insert(['client_id' => $this->order->client_id, 'ingredient_id' => $starch]);
        $sauce = Dish::create(['name' => 'Соус'])->id;
        DB::table('dish_ingredients')->insert(['dish_id' => $sauce, 'type' => 'product', 'ingredient_id' => $starch, 'net_weight_g' => 5]);
        $dish = Dish::create(['name' => 'Курка в соусі'])->id;
        DB::table('dish_ingredients')->insert([
            ['dish_id' => $dish, 'type' => 'product', 'ingredient_id' => $this->ingredient('філе куряче'), 'net_weight_g' => 150, 'child_dish_id' => null],
            ['dish_id' => $dish, 'type' => 'pf', 'ingredient_id' => null, 'net_weight_g' => 40, 'child_dish_id' => $sauce],
        ]);
        $this->gateway->script = [[['dish_composition', ['dish_id' => $dish]], ['find_ingredients', ['q' => 'філе']]]];

        RunAgentTask::dispatchSync($this->task());

        $composition = json_decode($this->gateway->toolResult(1, 0), true);
        $this->assertSame(['філе куряче', false, null], [$composition['ingredients'][0]['name'], $composition['ingredients'][0]['excluded_for_client'], $composition['ingredients'][0]['via']]);
        $this->assertSame(['крохмаль кукурудзяний', true, 'Соус'], [$composition['ingredients'][1]['name'], $composition['ingredients'][1]['excluded_for_client'], $composition['ingredients'][1]['via']]);
        $this->assertSame('філе куряче', json_decode($this->gateway->toolResult(1, 1), true)[0]['name']);
    }

    public function test_a_refused_menu_goes_back_to_the_agent_as_an_error(): void
    {
        $peas = $this->ingredient('горошок');
        DB::table('client_ingredient_exclusion')->insert(['client_id' => $this->order->client_id, 'ingredient_id' => $peas]);
        $dish = Dish::create(['name' => 'Курка з горошком'])->id;
        DB::table('dish_ingredients')->insert(['dish_id' => $dish, 'type' => 'product', 'ingredient_id' => $peas, 'net_weight_g' => 50]);
        $this->gateway->script = [
            [['submit_menu', ['summary' => 'x', 'payload' => ['days' => [['date' => '2026-10-14', 'meals' => [['meal_type_id' => $this->lunch, 'dish_id' => $dish]]]]]]]],
            [['finish', ['summary' => 'не вийшло']]],
        ];

        RunAgentTask::dispatchSync($this->task());

        $this->assertSame(0, AiProposal::count());
        $this->assertStringContainsString('[V1] 14.10 Обід: «Курка з горошком» містить «горошок»', $this->gateway->toolResult(1, 0));
        $this->assertTrue($this->gateway->toolResultIsError(1, 0));
    }

    public function test_a_question_pauses_the_task_and_the_answer_resumes_it(): void
    {
        $this->gateway->script = [
            [['ask_manager', ['question' => 'Цілі білка немає — скільки?']]],
            [['finish', ['summary' => 'Ок, 140 г']]],
        ];
        $task = $this->task();

        RunAgentTask::dispatchSync($task);
        $this->assertSame('waiting_human', $task->fresh()->status);
        $this->assertSame('Цілі білка немає — скільки?', $task->events()->where('kind', 'question')->value('text'));
        $this->assertSame(1, $this->gateway->calls);

        RunAgentTask::dispatchSync($task->fresh(), '140 г');

        $this->assertSame('done', $task->fresh()->status);
        $this->assertSame('Відповідь менеджера: 140 г', $this->gateway->toolResult(1, 0));
    }

    public function test_the_daily_cap_stops_the_agent_before_spending_more(): void
    {
        config(['services.ai_agents.runtime.daily_cap_usd' => 1]);
        AiRun::create(['purpose' => 'agent_menu', 'model' => 'claude-opus-5-5', 'status' => 'ok', 'cost_usd' => 1.2]);
        $this->gateway->script = [[['task_context', []]]];

        RunAgentTask::dispatchSync($task = $this->task());

        $this->assertSame('failed', $task->fresh()->status);
        $this->assertStringContainsString('Денна стеля', $task->fresh()->error);
        $this->assertSame(0, $this->gateway->calls);
    }

    public function test_the_step_limit_stops_a_looping_agent(): void
    {
        config(['services.ai_agents.runtime.agents.menu.max_steps' => 2]);
        $this->gateway->script = [[['progress', ['text' => 'a']]], [['progress', ['text' => 'b']]], [['progress', ['text' => 'c']]]];

        RunAgentTask::dispatchSync($task = $this->task());

        $this->assertSame(['failed', 2], [$task->fresh()->status, $task->fresh()->steps]);
    }
}

/** Модель-сценарій: кожен крок — список викликів інструментів [name, input]. */
class ScriptedGateway implements ModelGateway
{
    public array $script = [];
    public int $calls = 0;
    /** @var array<int, array> повідомлення, які бачила модель на кожному кроці */
    public array $seen = [];

    public function step(string $model, string $effort, string $system, array $messages, array $tools): array
    {
        $this->seen[] = $messages;
        $uses = $this->script[$this->calls] ?? [['finish', ['summary' => 'кінець сценарію']]];
        $n = ++$this->calls;

        return [
            'content' => array_map(fn ($u, $i) => ['type' => 'tool_use', 'id' => "tu{$n}_{$i}", 'name' => $u[0], 'input' => $u[1]], $uses, array_keys($uses)),
            'stop_reason' => 'tool_use',
            'model' => $model,
            'usage' => ['input' => 1000, 'output' => 200, 'cache_read' => 0, 'cache_write' => 0],
        ];
    }

    /** Результат i-го інструменту, який модель побачила на кроці $step (1 = другий виклик моделі). */
    public function toolResult(int $step, int $i): string
    {
        return (string) (end($this->seen[$step])['content'][$i]['content'] ?? '');
    }

    public function toolResultIsError(int $step, int $i): bool
    {
        return (bool) (end($this->seen[$step])['content'][$i]['is_error'] ?? false);
    }
}
