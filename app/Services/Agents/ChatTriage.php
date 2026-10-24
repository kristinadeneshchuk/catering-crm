<?php

namespace App\Services\Agents;

use App\Models\AiAgent;
use App\Models\AiAgentTask;
use App\Models\AiRun;
use App\Models\InboxEvent;
use App\Models\Order;
use App\Models\User;
use App\Services\Agents\Runtime\AgentLoop;
use App\Services\Agents\Runtime\ModelGateway;
use App\Services\Inbox\InboxReadClient;
use Carbon\Carbon;
use Throwable;

/**
 * Нове в чаті клієнта → дешеве сортування (Sonnet, effort low) → задача агенту
 * «Переписки», лише якщо є що робити: виключення, скарга, перенос/пауза, контакти,
 * побажання до меню, питання без відповіді. Звичайні «дякую» й «ок» — пропускаємо.
 */
class ChatTriage
{
    public const KINDS = ['exclusion', 'complaint', 'day_change', 'contact', 'menu_wish', 'unanswered'];

    private const PROMPT = <<<'TXT'
Ти сортуєш нові повідомлення клієнта служби доставки здорового харчування (раціони на день).
Визнач, чи є в НОВИХ повідомленнях клієнта (позначені [НОВЕ]) щось, що треба внести в CRM або на що має зреагувати менеджер:
- exclusion — не їсть / алергія / «прибрати» продукт;
- complaint — скарга на якість, вагу, не ту страву, відсутній прийом, запізнення;
- day_change — пауза, перенос, відʼїзд, скасування дня, зміна дат;
- contact — новий телефон, адреса, поверх, код, «де лишати», час доставки;
- menu_wish — побажання до меню (більше/менше чогось, улюблене, «не повторювати»);
- unanswered — пряме питання клієнта, на яке менеджер ще не відповів.
Подяки, «ок», оплата, привітання, питання, на які вже відповіли — не дія.
Відповідай ЛИШЕ JSON без пояснень: {"action": true|false, "kinds": [...], "why": "одне речення українською"}
TXT;

    public function __construct(private InboxReadClient $inbox, private ModelGateway $gateway) {}

    /** @return string що зроблено (для журналу) */
    public function run(int $conversationId): string
    {
        $agent = AiAgent::serverChats();
        $cursorKey = "chat:{$conversationId}:last_message_id";
        $cursor = (int) ($agent->memory()->where('key', $cursorKey)->value('value') ?? 0);

        $conv = $this->inbox->conversation($conversationId);
        $messages = collect($this->inbox->page($conversationId, 40)['messages']);
        $fresh = $messages->filter(fn ($m) => (int) $m['id'] > $cursor && empty($m['is_note']) && ($m['direction'] ?? '') === 'in');
        $lastId = (int) ($messages->max('id') ?? $cursor);
        $events = InboxEvent::where('conversation_id', $conversationId)->where('status', 'queued');

        if ($fresh->isEmpty()) {
            $events->update(['status' => 'processed', 'note' => 'нового від клієнта немає']);
            $this->remember($agent, $cursorKey, $lastId);

            return 'нового немає';
        }

        $clientId = (int) ($conv['contact']['crm_client_id'] ?? 0);
        if (!$clientId) {
            $events->update(['status' => 'skipped', 'note' => 'чат не привʼязаний до клієнта CRM']);
            $this->remember($agent, $cursorKey, $lastId);

            return 'чат без клієнта CRM';
        }

        // Агент ще працює з цим чатом — дочитає нове наступним проходом.
        if (AiAgentTask::where('ai_agent_id', $agent->id)->whereIn('status', AiAgentTask::OPEN)->where('payload->conversation_id', $conversationId)->exists()) {
            return 'задача по чату ще відкрита';
        }

        $verdict = $this->classify($conversationId, $messages, $cursor);
        if (!($verdict['action'] ?? false)) {
            $events->update(['status' => 'processed', 'note' => mb_substr('нічого: ' . ($verdict['why'] ?? ''), 0, 250)]);
            $this->remember($agent, $cursorKey, $lastId);

            return 'нічого робити';
        }

        $order = Order::where('client_id', $clientId)->whereIn('status', ['new', 'active', 'paused'])->latest('start_date')->first();
        $by = User::find((int) explode(',', (string) config('services.finance.super_admin_ids'))[0]) ?? User::firstOrFail();
        $kinds = array_values(array_intersect((array) ($verdict['kinds'] ?? []), self::KINDS));
        $task = app(TaskDesk::class)->assign($agent, $by, [
            'template' => 'chat_check',
            'client_id' => $clientId,
            'order_id' => $order?->id,
            'details' => trim(($verdict['why'] ?? '') . ($kinds ? ' [' . implode(', ', $kinds) . ']' : '')),
            'shadow' => (bool) config('services.ai_agents.chats.shadow', true),
            'payload' => [
                'conversation_id' => $conversationId,
                'from_message_id' => $cursor,
                'new_message_ids' => $fresh->pluck('id')->values()->all(),
                'kinds' => $kinds,
                'brand' => $conv['account']['name'] ?? null,
                'link' => $conv['link'] ?? null,
            ],
        ]);
        $events->update(['status' => 'processed', 'note' => "задача #{$task->id}"]);
        $this->remember($agent, $cursorKey, $lastId);

        return "задача #{$task->id}";
    }

    /** Чат як текст для моделі: нове позначене, нотатки менеджерів підписані. */
    public static function transcript($messages, int $cursor): string
    {
        return collect($messages)->map(function ($m) use ($cursor) {
            $who = !empty($m['is_note']) ? 'Нотатка менеджера' : (($m['direction'] ?? '') === 'in' ? 'Клієнт' : 'Менеджер');
            $at = !empty($m['sent_at']) ? Carbon::parse($m['sent_at'])->timezone('Europe/Kyiv')->format('d.m H:i') : '';
            $new = (int) $m['id'] > $cursor && ($m['direction'] ?? '') === 'in' && empty($m['is_note']) ? '[НОВЕ] ' : '';

            return "{$new}#{$m['id']} {$at} {$who}: " . trim((string) ($m['text'] ?? ''));
        })->implode("\n");
    }

    private function classify(int $conversationId, $messages, int $cursor): array
    {
        $model = (string) config('services.ai_agents.chats.triage_model', 'claude-sonnet-5-5');
        $started = microtime(true);
        try {
            $res = $this->gateway->step($model, (string) config('services.ai_agents.chats.triage_effort', 'low'), self::PROMPT,
                [['role' => 'user', 'content' => self::transcript($messages->take(-20), $cursor)]], []);
        } catch (Throwable $e) {
            report($e);

            return ['action' => true, 'kinds' => [], 'why' => 'Сортування не вдалося — перевірте чат вручну'];
        }
        $text = collect($res['content'])->where('type', 'text')->pluck('text')->implode('');
        $json = json_decode(trim(preg_replace('/^```(json)?|```$/m', '', $text)), true);
        AiRun::create([
            'purpose' => 'agent_chats_triage', 'model' => $res['model'] ?: $model, 'status' => is_array($json) ? 'ok' : 'invalid',
            'input_meta' => ['conversation_id' => $conversationId], 'output' => is_array($json) ? $json : null,
            'input_tokens' => $res['usage']['input'], 'output_tokens' => $res['usage']['output'], 'cache_read_tokens' => $res['usage']['cache_read'],
            'cost_usd' => AgentLoop::costOf($res['model'] ?: $model, $res['usage']),
            'latency_ms' => (int) round((microtime(true) - $started) * 1000),
        ]);

        // Незрозуміла відповідь — краще перевірити, ніж пропустити.
        return is_array($json) ? $json : ['action' => true, 'kinds' => [], 'why' => 'Сортування дало незрозумілу відповідь — перевірте чат'];
    }

    private function remember(AiAgent $agent, string $key, int $id): void
    {
        $agent->memory()->updateOrCreate(['key' => $key], ['value' => $id]);
    }
}
