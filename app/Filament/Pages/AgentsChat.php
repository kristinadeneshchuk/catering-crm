<?php

namespace App\Filament\Pages;

use App\Models\AiAgent;
use App\Models\AiAgentTask;
use App\Models\AiProposal;
use App\Models\Dish;
use App\Services\Agents\ChatProposals;
use App\Services\Agents\ChatRouter;
use App\Services\Agents\ProposalDesk;
use App\Services\Agents\TaskDesk;
use App\Services\Inbox\InboxReadClient;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Collection;
use Throwable;

/**
 * «ШІ-чат»: менеджер пише агентам звичайними словами, бачить хід роботи й
 * погоджує результат кнопками прямо в розмові. Розмова = задача агента
 * (docs/tz-ai-agents-in-crm.md §3), «Переписки» приходять сюди ж.
 */
class AgentsChat extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-chat-bubble-left-right';
    protected static ?string $navigationLabel = 'ШІ-чат';
    protected static ?string $title = 'ШІ-чат';
    protected static ?string $navigationGroup = 'ШІ';
    protected static ?int $navigationSort = 0;
    protected static ?string $slug = 'ai';
    protected static string $view = 'filament.ai.agents-chat';

    public ?int $taskId = null;
    public string $draft = '';
    public string $filter = 'all';
    /** Кандидати, коли за іменем знайшлось кілька клієнтів. */
    public array $candidates = [];
    public array $pendingIntent = [];
    /** Відповідь системи в новій розмові (уточнення, «не знайшов клієнта»). */
    public ?string $notice = null;

    public static function canAccess(): bool
    {
        $u = auth()->user();

        return $u && ($u->isAdmin() || $u->isManager());
    }

    public static function getNavigationBadge(): ?string
    {
        try {
            $n = AiAgentTask::where('status', 'waiting_human')->count() + AiProposal::where('status', 'pending')->whereNotNull('ai_agent_task_id')->count();
        } catch (Throwable) {
            return null;
        }

        return $n ? (string) $n : null;
    }

    public function mount(): void
    {
        $this->taskId = request()->integer('task') ?: null;
        if ($client = request()->integer('client')) {
            $name = \App\Models\Client::whereKey($client)->value('name');
            $this->draft = $name ? "{$name}: " : '';
        }
    }

    public function select(int $id): void
    {
        $this->taskId = $id;
        $this->reset('candidates', 'pendingIntent', 'notice');
    }

    public function newConversation(): void
    {
        $this->reset('taskId', 'candidates', 'pendingIntent', 'notice', 'draft');
    }

    public function send(): void
    {
        $text = trim($this->draft);
        if ($text === '') return;
        $desk = app(TaskDesk::class);
        $task = $this->taskId ? AiAgentTask::find($this->taskId) : null;

        if ($task) {
            $task->isOpen() ? $desk->answer($task, auth()->user(), $text) : $desk->restart($task, auth()->user(), $text);
            $this->reset('draft');

            return;
        }

        $res = app(ChatRouter::class)->parse($text);
        $intent = $res['intent'] + ['source_text' => $text];
        if (empty($intent['agent'])) {
            $this->notice = $intent['clarify'] ?? 'Не зрозумів доручення. Напишіть, кому й що зробити.';

            return;
        }
        if (empty($intent['client'])) {
            $this->notice = 'Для якого клієнта? Напишіть імʼя або прізвище.';

            return;
        }
        if (!$res['candidates']) {
            $this->notice = "Не знайшов клієнта «{$intent['client']}». Перевірте імʼя або відкрийте картку клієнта й натисніть «Доручити агенту».";

            return;
        }
        if (count($res['candidates']) > 1) {
            $this->candidates = $res['candidates'];
            $this->pendingIntent = $intent;
            $this->notice = 'Знайшов кількох — кого саме?';

            return;
        }
        $this->start($intent, $res['candidates'][0]);
    }

    public function pick(int $i): void
    {
        if (!isset($this->candidates[$i])) return;
        $this->start($this->pendingIntent, $this->candidates[$i]);
    }

    private function start(array $intent, array $candidate): void
    {
        $agent = $intent['agent'] === 'chats' ? AiAgent::serverChats() : AiAgent::serverMenu();
        $data = [
            'template' => $intent['template'] ?? ($intent['agent'] === 'chats' ? 'chat_check' : 'menu_period'),
            'order_id' => $candidate['order_id'],
            'client_id' => $candidate['client_id'],
            'period_from' => $intent['period_from'] ?? null,
            'period_to' => $intent['period_to'] ?? null,
            'details' => trim(($intent['details'] ?? '') . "\n\nДослівно: «{$intent['source_text']}»"),
        ];

        if ($intent['agent'] === 'chats') {
            $conv = $this->findChat((int) $candidate['client_id']);
            if (!$conv) {
                $this->notice = 'У Inbox немає чату, привʼязаного до цього клієнта (або Inbox недоступний).';

                return;
            }
            $data['payload'] = ['conversation_id' => $conv['conversation']['id'], 'from_message_id' => 0,
                'brand' => $conv['account']['name'] ?? null, 'link' => $conv['link'] ?? null, 'kinds' => []];
            $data['shadow'] = (bool) config('services.ai_agents.chats.shadow', true);
        } elseif (!$candidate['order_id']) {
            $this->notice = 'У клієнта немає активного замовлення — меню скласти нема на що.';

            return;
        }

        $task = app(TaskDesk::class)->assign($agent, auth()->user(), $data);
        $this->reset('draft', 'candidates', 'pendingIntent', 'notice');
        $this->taskId = $task->id;
    }

    private function findChat(int $clientId): ?array
    {
        try {
            foreach (ChatProposals::twins($clientId) as $cid) {
                $found = app(InboxReadClient::class)->conversationsForClient($cid);
                if ($found) return collect($found)->sortByDesc(fn ($c) => $c['conversation']['last_client_message_at'] ?? '')->first();
            }
        } catch (Throwable $e) {
            report($e);
        }

        return null;
    }

    public function approve(int $id): void
    {
        Notification::make()->title(app(ProposalDesk::class)->approve(AiProposal::findOrFail($id), auth()->user()))->send();
    }

    public function changes(int $id): void
    {
        $text = trim($this->draft);
        if ($text === '') {
            Notification::make()->title('Напишіть унизу, що змінити, і натисніть «Правки» ще раз')->warning()->send();

            return;
        }
        $p = AiProposal::findOrFail($id);
        Notification::make()->title(app(ProposalDesk::class)->reject($p, auth()->user(), $text, true))->send();
        // Агент на сервері одразу бере правки в роботу.
        if ($p->task && $p->task->agent->runsOnServer()) app(TaskDesk::class)->restart($p->task, auth()->user(), "Правки до пропозиції #{$p->id}: {$text}");
        $this->reset('draft');
    }

    public function reject(int $id): void
    {
        Notification::make()->title(app(ProposalDesk::class)->reject(AiProposal::findOrFail($id), auth()->user(), trim($this->draft) ?: null))->send();
        $this->reset('draft');
    }

    protected function getViewData(): array
    {
        $tasks = AiAgentTask::with(['agent:id,name,slug', 'client:id,name'])
            ->withCount(['proposals as pending_count' => fn ($q) => $q->where('status', 'pending')])
            ->when($this->filter === 'mine', fn ($q) => $q->where(fn ($w) => $w->where('status', 'waiting_human')->orWhereHas('proposals', fn ($p) => $p->where('status', 'pending'))))
            ->latest('id')->limit(60)->get();
        $task = $this->taskId ? AiAgentTask::with(['agent', 'client', 'creator', 'events.user'])->find($this->taskId) : null;

        return [
            'tasks' => $tasks,
            'task' => $task,
            'timeline' => $task ? $this->timeline($task) : collect(),
        ];
    }

    /** Стрічка розмови: доручення, хід роботи, питання/відповіді, картки пропозицій — за часом. */
    private function timeline(AiAgentTask $task): Collection
    {
        $items = $task->events->map(fn ($e) => ['at' => $e->created_at, 'kind' => $e->kind, 'text' => $e->text, 'user' => $e->user?->name, 'id' => 'e' . $e->id]);
        $proposals = $task->proposals()->latest('id')->get()->map(fn (AiProposal $p) => ['at' => $p->created_at, 'kind' => 'proposal', 'p' => $p, 'preview' => $this->preview($p), 'id' => 'p' . $p->id]);

        return $items->concat($proposals)->sortBy([['at', 'asc'], ['id', 'asc']])->values();
    }

    /** Короткий вміст картки: перші дні меню або суть пропозиції з переписки. */
    private function preview(AiProposal $p): array
    {
        if ($p->type !== 'individual_week_menu') return ['lines' => array_slice(explode("\n", (string) $p->summary), 0, 6)];

        $pl = (array) $p->payload;
        $names = Dish::whereIn('id', collect($pl['days'] ?? [])->flatMap(fn ($d) => collect($d['meals'] ?? [])->pluck('dish_id'))->filter())->pluck('name', 'id');
        $new = collect($pl['new_dishes'] ?? [])->keyBy('ref');
        $days = collect($pl['days'] ?? [])->map(fn ($d) => \Carbon\Carbon::parse($d['date'])->format('d.m') . ' · ' . collect($d['meals'] ?? [])
            ->map(fn ($m) => !empty($m['dish_id']) ? ($names[$m['dish_id']] ?? '#' . $m['dish_id']) : ($new[$m['dish_ref'] ?? '']['name'] ?? '?'))
            ->map(fn ($n) => trim(preg_replace('/^Інд · /u', '', $n)))->implode(' · '));
        $warn = collect($p->checks)->where('level', 'warn')->count();

        return [
            'summary' => mb_strimwidth((string) preg_replace('/\n⚠️.*/su', '', (string) $p->summary), 0, 600, '…'),
            'days' => $days->all(),
            'checks' => $p->checks === null ? null : ($warn ? "перевірки: {$warn} попередж." : 'перевірки пройдено'),
            'warn' => $warn,
        ];
    }
}
