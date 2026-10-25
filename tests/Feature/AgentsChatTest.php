<?php

namespace Tests\Feature;

use App\Filament\Pages\AgentsChat;
use App\Jobs\RunAgentTask;
use App\Models\AiAgent;
use App\Models\AiAgentTask;
use App\Models\AiProposal;
use App\Services\Agents\ProposalDesk;
use App\Services\Agents\Runtime\ModelGateway;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Support\ScriptedGateway;

/** «ШІ-чат»: доручення звичайними словами, хід роботи, картки з кнопками. */
class AgentsChatTest extends AgentsTestCase
{
    protected ScriptedGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();
        (require database_path('migrations/2026_09_16_100000_create_ai_runs_table.php'))->up();
        $this->order->forceFill(['status' => 'active'])->saveQuietly();
        $this->gateway = new ScriptedGateway();
        $this->app->instance(ModelGateway::class, $this->gateway);
        Queue::fake();
        $this->actingAs($this->user(12, 'manager'));
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_a_plain_sentence_becomes_a_task_for_the_right_client(): void
    {
        $this->gateway->script = ['{"agent": "menu", "template": "menu_period", "client": "Гоголю", "period_from": "2026-10-14", "period_to": "2026-10-16", "details": "більше риби", "clarify": null}'];

        Livewire::test(AgentsChat::class)
            ->set('draft', 'Склади меню Гоголю на 14–16.10, більше риби')
            ->call('send')
            ->assertSet('draft', '')
            ->assertSee('Гоголь Денис');

        $task = AiAgentTask::sole();
        $this->assertSame(['menu', $this->order->id, '2026-10-14', '2026-10-16'], [$task->agent->slug, $task->order_id, $task->period_from->toDateString(), $task->period_to->toDateString()]);
        $this->assertStringContainsString('Дослівно: «Склади меню Гоголю на 14–16.10, більше риби»', $task->details);
        Queue::assertPushed(RunAgentTask::class);
    }

    public function test_several_matching_clients_are_offered_as_buttons(): void
    {
        DB::table('clients')->insert(['name' => 'Гоголь Ольга']);
        $this->gateway->script = ['{"agent": "menu", "client": "Гоголь", "period_from": null, "period_to": null, "details": "", "clarify": null}'];

        $page = Livewire::test(AgentsChat::class)->set('draft', 'меню Гоголь на тиждень')->call('send')
            ->assertSee('Знайшов кількох')->assertSee('Гоголь Ольга');
        $this->assertSame(0, AiAgentTask::count());
        $this->assertTrue($page->get('candidates')[0]['active']); // з активним замовленням — першим

        $page->call('pick', 0);
        $this->assertSame($this->order->client_id, AiAgentTask::sole()->client_id);
    }

    public function test_an_unclear_message_gets_a_question_back(): void
    {
        $this->gateway->script = ['{"agent": null, "client": null, "clarify": "Що саме зробити?"}'];

        Livewire::test(AgentsChat::class)->set('draft', 'привіт')->call('send')->assertSee('Що саме зробити?');
        $this->assertSame(0, AiAgentTask::count());
    }

    public function test_the_conversation_shows_progress_questions_and_cards_and_takes_answers(): void
    {
        $menu = AiAgent::serverMenu();
        $task = $menu->tasks()->create(['title' => 'Меню', 'status' => 'waiting_human', 'client_id' => $this->order->client_id, 'order_id' => $this->order->id, 'details' => 'більше риби']);
        $task->log('tool', 'Читаю картку клієнта, замовлення й виключення');
        $task->log('question', 'Скільки білка на день?');
        $dish = \App\Models\Dish::create(['name' => 'Інд · Тунець гриль'])->id;
        AiProposal::create(['ai_agent_id' => $menu->id, 'ai_agent_task_id' => $task->id, 'type' => 'individual_week_menu', 'title' => 'Меню 14–15.10', 'summary' => 'Тунець тричі', 'status' => 'pending', 'order_id' => $this->order->id,
            'checks' => [], 'payload' => ['order_id' => $this->order->id, 'days' => [['date' => '2026-10-14', 'meals' => [['meal_type_id' => $this->lunch, 'dish_id' => $dish]]]]]]);

        Livewire::test(AgentsChat::class, [])->call('select', $task->id)
            ->assertSee('✓ Читаю картку клієнта')->assertSee('Скільки білка на день?')
            ->assertSee('14.10 · Тунець гриль')->assertSee('перевірки пройдено')->assertSee('Погодити')
            ->set('draft', '140 г')->call('send');

        $this->assertSame('140 г', $task->events()->where('kind', 'answer')->value('text'));
        Queue::assertPushed(RunAgentTask::class, fn ($j) => $j->answer === '140 г');
    }

    public function test_a_task_for_an_agent_on_the_mac_is_not_shown_as_running_here(): void
    {
        $inbox = AiAgent::create(['slug' => 'inbox', 'name' => 'Inbox — переписки й розсилка', 'allowed_types' => ['client_message']]);
        $task = $inbox->tasks()->create(['title' => 'Надіслати меню клієнту: Гоголь', 'status' => 'new', 'details' => 'Меню погоджено. Звірся з рецензентом.']);

        Livewire::test(AgentsChat::class)->call('select', $task->id)
            ->assertSee('чекає агента на Маку')
            ->assertSee('Цю задачу виконує агент на Маку')
            ->assertDontSee('… працює');
    }

    public function test_changes_on_a_card_send_the_server_agent_back_to_work(): void
    {
        $menu = AiAgent::serverMenu();
        $task = $menu->tasks()->create(['title' => 'Меню', 'status' => 'done', 'client_id' => $this->order->client_id, 'order_id' => $this->order->id]);
        $p = AiProposal::create(['ai_agent_id' => $menu->id, 'ai_agent_task_id' => $task->id, 'type' => 'individual_week_menu', 'title' => 'Меню', 'status' => 'pending', 'order_id' => $this->order->id, 'payload' => ['order_id' => $this->order->id, 'days' => []]]);
        app()->instance(ProposalDesk::class, app(ProposalDesk::class));

        Livewire::test(AgentsChat::class)->call('select', $task->id)->set('draft', 'без креветок у середу')->call('changes', $p->id);

        $this->assertSame(['changes_requested', 'без креветок у середу'], [$p->fresh()->status, $p->fresh()->review_comment]);
        $this->assertSame('new', $task->fresh()->status);
        Queue::assertPushed(RunAgentTask::class, fn ($j) => str_contains((string) $j->answer, 'без креветок у середу'));
    }
}
