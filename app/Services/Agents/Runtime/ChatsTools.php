<?php

namespace App\Services\Agents\Runtime;

use App\Models\AiAgentTask;
use App\Models\AiProposal;
use App\Models\Client;
use App\Models\ClientDietProfile;
use App\Models\Order;
use App\Services\Agents\ChatProposals;
use App\Services\Agents\ChatTriage;
use App\Services\Agents\IngredientGroupCatalog;
use App\Services\Agents\ProposalDesk;
use App\Services\Inbox\InboxReadClient;
use Illuminate\Support\Facades\DB;

/**
 * Інструменти агента «Переписки»: читати чат з Inbox (лише читання), картку
 * клієнта й пропонувати зміни. Клієнтам не пише, у бойові дані не пише.
 */
class ChatsTools implements AgentTools
{
    public function __construct(private AiAgentTask $task) {}

    public function definitions(): array
    {
        $evidence = ['type' => 'object', 'description' => 'доказ із чату', 'properties' => [
            'message_id' => ['type' => 'integer'], 'quote' => ['type' => 'string', 'description' => 'дослівно слова клієнта, ≤ 200 символів'],
        ], 'required' => ['message_id', 'quote']];
        $base = fn (array $props, array $required) => ['type' => 'object', 'properties' => $props + ['summary' => ['type' => 'string', 'description' => 'одним-двома реченнями для менеджера'], 'evidence' => $evidence], 'required' => array_merge($required, ['summary', 'evidence'])];

        return [
            $this->tool('chat_context', 'Картка клієнта (і дублі за телефоном), активні замовлення й дні доставки на 14 днів, виключення, профіль харчування, бренд, вже подані мною пропозиції. Викликати першим.', ['type' => 'object', 'properties' => new \stdClass]),
            $this->tool('read_chat', 'Повідомлення чату з Inbox, від старих до нових. Нові для цієї задачі позначені [НОВЕ]. Для давнішої історії — before_message_id.', [
                'type' => 'object', 'properties' => ['before_message_id' => ['type' => 'integer'], 'limit' => ['type' => 'integer']],
            ]),
            $this->tool('find_ingredients', 'Знайти продукти довідника (id) для виключення.', ['type' => 'object', 'properties' => ['q' => ['type' => 'string']], 'required' => ['q']]),
            $this->tool('propose_exclusion', 'Клієнт не їсть / алергія / «прибрати». Продукти (ingredient_ids) і/або цілі групи (group_slugs: ' . implode(', ', array_keys(IngredientGroupCatalog::all())) . '). Ляже на всі картки людини.', $base([
                'ingredient_ids' => ['type' => 'array', 'items' => ['type' => 'integer']],
                'group_slugs' => ['type' => 'array', 'items' => ['type' => 'string']],
            ], [])),
            $this->tool('propose_profile', 'М\'які побажання: «краще ні», «більше», правила частоти, нотатка для меню.', $base([
                'avoid_add' => ['type' => 'array', 'items' => ['type' => 'string']],
                'want_more_add' => ['type' => 'array', 'items' => ['type' => 'string']],
                'rules_add' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => ['kind' => ['type' => 'string', 'enum' => ['max_per_day', 'max_per_week', 'text']], 'group_slug' => ['type' => 'string'], 'value' => ['type' => 'integer'], 'text' => ['type' => 'string']], 'required' => ['kind', 'text']]],
                'notes_add' => ['type' => 'string'],
            ], [])),
            $this->tool('propose_contact', 'Новий телефон, адреса, підʼїзд, квартира, поверх, «де лишати» (delivery_comment).', $base([
                'phone' => ['type' => 'string'], 'address' => ['type' => 'string'], 'address_entrance' => ['type' => 'string'],
                'address_apartment' => ['type' => 'string'], 'address_floor' => ['type' => 'string'], 'delivery_comment' => ['type' => 'string'],
            ], [])),
            $this->tool('propose_day_change', 'Пауза / перенос / скасування / відновлення днів доставки. Дати РІВНО як написав клієнт (Y-m-d).', $base([
                'order_id' => ['type' => 'integer'],
                'action' => ['type' => 'string', 'enum' => ['pause', 'move', 'cancel_day', 'resume']],
                'dates' => ['type' => 'array', 'items' => ['type' => 'string']],
                'new_dates' => ['type' => 'array', 'items' => ['type' => 'string']],
            ], ['order_id', 'action', 'dates'])),
            $this->tool('report_complaint', 'Скарга на якість, вагу, не ту страву, відсутній прийом, запізнення. Компенсацій не обіцяти — вирішує власник.', $base([
                'issue' => ['type' => 'string'], 'date' => ['type' => 'string'], 'dish' => ['type' => 'string'],
                'severity' => ['type' => 'string', 'enum' => ['low', 'medium', 'high']],
            ], ['issue'])),
            $this->tool('progress', 'Коротко написати менеджеру, що зараз робите.', ['type' => 'object', 'properties' => ['text' => ['type' => 'string']], 'required' => ['text']]),
            $this->tool('ask_manager', 'Спитати менеджера, коли без нього не розібратись (незрозуміло, чий чат, суперечливі дати). Робота стане на паузу.', ['type' => 'object', 'properties' => ['question' => ['type' => 'string']], 'required' => ['question']]),
            $this->tool('finish', 'Завершити: що знайдено й подано, що лишилось менеджеру (напр. питання клієнта без відповіді).', ['type' => 'object', 'properties' => ['summary' => ['type' => 'string']], 'required' => ['summary']]),
        ];
    }

    public function call(string $name, array $input): ToolResult
    {
        return match ($name) {
            'chat_context'       => ToolResult::ok($this->context()),
            'read_chat'          => ToolResult::ok($this->readChat($input)),
            'find_ingredients'   => ToolResult::ok(DB::table('ingredients')->where('name', 'like', '%' . ($input['q'] ?? '') . '%')->limit(30)->pluck('name', 'id')),
            'propose_exclusion'  => $this->submit('client_exclusion_add', $input),
            'propose_profile'    => $this->submit('client_profile_update', $input),
            'propose_contact'    => $this->submit('client_contact_update', $input),
            'propose_day_change' => $this->submit('order_day_change', $input),
            'report_complaint'   => $this->submit('client_complaint', $input),
            'progress'           => $this->progress((string) ($input['text'] ?? '')),
            'ask_manager'        => ToolResult::pause((string) ($input['question'] ?? '')),
            'finish'             => ToolResult::finish((string) ($input['summary'] ?? '')),
            default              => ToolResult::error("Невідомий інструмент «{$name}»"),
        };
    }

    private function tool(string $name, string $description, array $schema): array
    {
        return ['name' => $name, 'description' => $description, 'input_schema' => $schema];
    }

    private function context(): array
    {
        $client = Client::with(['ingredientExclusions', 'dishExclusions'])->findOrFail($this->task->client_id);
        $twins = ChatProposals::twins($client->id);
        $profile = ClientDietProfile::where('client_id', $client->id)->first();
        $orders = Order::whereIn('client_id', $twins)->whereIn('status', ['new', 'active', 'paused'])->latest('start_date')->get();

        return [
            'brand_chat' => $this->task->payload['brand'] ?? null,
            'what_triage_saw' => ['kinds' => $this->task->payload['kinds'] ?? [], 'why' => $this->task->details],
            'client' => ['id' => $client->id, 'name' => $client->name, 'phone' => $client->phone, 'address' => trim("{$client->address} під.{$client->address_entrance} кв.{$client->address_apartment} пов.{$client->address_floor}"),
                'delivery_comment' => $client->delivery_comment, 'menu_brief' => $client->menu_brief, 'allergies' => $client->allergies],
            'duplicate_cards' => Client::whereIn('id', array_diff($twins, [$client->id]))->pluck('name', 'id'),
            'exclusions' => [
                'ingredients' => $client->ingredientExclusions->pluck('name', 'id'),
                'groups' => (array) ($profile?->excluded_groups ?? []),
                'dishes' => $client->dishExclusions->pluck('name', 'id'),
            ],
            'profile' => $profile?->only(['mode', 'avoid', 'want_more', 'rules', 'notes']),
            'orders' => $orders->map(fn (Order $o) => [
                'id' => $o->id, 'client_id' => $o->client_id, 'status' => $o->status, 'menu_type' => $o->menu_type, 'calories' => $o->calories,
                'start' => $o->start_date, 'end' => $o->end_date, 'delivery_time' => $o->delivery_time,
                'next_days' => DB::table('order_days')->where('order_id', $o->id)->whereBetween('date', [now()->toDateString(), now()->addDays(14)->toDateString()])
                    ->orderBy('date')->pluck('date')->map(fn ($d) => substr((string) $d, 0, 10)),
            ])->values(),
            'my_open_proposals' => AiProposal::where('ai_agent_id', $this->task->ai_agent_id)->whereIn('client_id', $twins)
                ->whereIn('status', ['pending', 'shadow'])->where('created_at', '>=', now()->subDays(7))->get(['id', 'type', 'summary'])
                ->map(fn ($p) => ['id' => $p->id, 'type' => $p->type, 'summary' => mb_substr((string) $p->summary, 0, 300)]),
            'today' => now()->timezone('Europe/Kyiv')->format('Y-m-d (l) H:i'),
        ];
    }

    private function readChat(array $input): array
    {
        $conv = (int) ($this->task->payload['conversation_id'] ?? 0);
        if (!$conv) return ['error' => 'у задачі немає чату'];
        $page = app(InboxReadClient::class)->page($conv, (int) ($input['limit'] ?? 40), isset($input['before_message_id']) ? (int) $input['before_message_id'] : null);

        return [
            'transcript' => ChatTriage::transcript($page['messages'], (int) ($this->task->payload['from_message_id'] ?? PHP_INT_MAX)),
            'has_more' => $page['has_more'],
            'oldest_message_id' => $page['messages'][0]['id'] ?? null,
        ];
    }

    private function submit(string $type, array $input): ToolResult
    {
        $payload = $input;
        unset($payload['summary']);
        $payload['client_id'] = $this->task->client_id;
        if ($type === 'order_day_change' && !in_array((int) ($payload['order_id'] ?? 0), Order::whereIn('client_id', ChatProposals::twins((int) $this->task->client_id))->pluck('id')->all(), true)) {
            return ToolResult::error('order_id: це не замовлення цього клієнта');
        }
        $payload['conversation_id'] = $this->task->payload['conversation_id'] ?? null;
        $res = app(ProposalDesk::class)->submit($this->task->agent, [
            'type' => $type, 'title' => ChatProposals::TYPES[$type], 'summary' => trim(($input['summary'] ?? '') . "\n«" . ($input['evidence']['quote'] ?? '') . '»'),
            'task_id' => $this->task->id, 'payload' => $payload,
        ]);
        if ($res['errors']) return ToolResult::error(['errors' => $res['errors']]);
        $p = $res['proposal'];
        $this->task->log('step', ($p->status === 'shadow' ? 'Тіньова пропозиція' : 'Подано на погодження') . ": {$p->title} (#{$p->id})", [], null, $p->id);

        return ToolResult::ok(['proposal_id' => $p->id, 'status' => $p->status]);
    }

    private function progress(string $text): ToolResult
    {
        $this->task->log('step', mb_substr($text, 0, 500));

        return ToolResult::ok(['ok' => true]);
    }
}
