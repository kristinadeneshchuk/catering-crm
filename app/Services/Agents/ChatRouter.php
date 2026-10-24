<?php

namespace App\Services\Agents;

use App\Models\AiRun;
use App\Models\Client;
use App\Models\Order;
use App\Services\Agents\Runtime\AgentLoop;
use App\Services\Agents\Runtime\ModelGateway;
use Carbon\Carbon;
use Throwable;

/**
 * «ШІ-чат»: менеджер пише звичайними словами — «склади меню Ковальовій на 27–31.10,
 * більше риби», «перевір переписку з Протасовим». Один дешевий виклик моделі
 * розбирає, кому що і на які дати; клієнта й замовлення знаходить уже код.
 */
class ChatRouter
{
    private const PROMPT = <<<'TXT'
Ти розбираєш доручення менеджера служби доставки здорового харчування для ШІ-агентів.
Агенти: "menu" — скласти персональне меню / заміни клієнту на дати; "chats" — перевірити переписку з клієнтом (виключення, скарги, паузи, адреса).
Сьогодні: {today}. Дати — у форматі Y-m-d; «на тиждень» без дат — з завтра на 7 днів; «на завтра» — завтра.
Відповідай ЛИШЕ JSON без пояснень:
{"agent": "menu"|"chats"|null, "template": "menu_period"|"replacements"|"chat_check"|"free", "client": "імʼя або прізвище як у тексті або null", "period_from": "Y-m-d"|null, "period_to": "Y-m-d"|null, "details": "побажання менеджера своїми словами, без імені й дат", "clarify": "одне коротке питання, якщо незрозуміло що робити, інакше null"}
TXT;

    public function __construct(private ModelGateway $gateway) {}

    /**
     * @return array{intent: array, candidates: array<int, array{client_id: int, order_id: ?int, label: string}>}
     */
    public function parse(string $text): array
    {
        $intent = $this->ask($text);
        $candidates = !empty($intent['client']) ? $this->candidates((string) $intent['client']) : [];

        return ['intent' => $intent, 'candidates' => $candidates];
    }

    /**
     * Клієнти за іменем зі свіжим замовленням — активні першими.
     *
     * @return array<int, array{client_id: int, order_id: ?int, label: string}>
     */
    public function candidates(string $name): array
    {
        $words = collect(preg_split('/\s+/u', mb_strtolower(trim($name))))->filter(fn ($w) => mb_strlen($w) >= 3)
            // Відмінки: «Ковальовій», «Протасовим» → основа слова.
            ->map(fn ($w) => mb_substr($w, 0, max(4, mb_strlen($w) - 3)));
        if ($words->isEmpty()) return [];

        $q = Client::query();
        // MySQL порівнює без регістру й сам; SQLite кирилицю в LOWER() не переводить — шукаємо обидва написання.
        foreach ($words as $w) $q->where(fn ($x) => $x->where('name', 'like', '%' . $w . '%')->orWhere('name', 'like', '%' . mb_convert_case($w, MB_CASE_TITLE) . '%'));

        return $q->limit(8)->get(['id', 'name'])->map(function (Client $c) {
            $order = Order::where('client_id', $c->id)->whereIn('status', ['new', 'active', 'paused'])->latest('start_date')->first();

            return [
                'client_id' => $c->id,
                'order_id' => $order?->id,
                'label' => $c->name . ($order ? " · замовлення #{$order->id}, {$order->calories} ккал, {$order->menu_type}" : ' · без активного замовлення'),
                'active' => (bool) $order,
            ];
        })->sortByDesc('active')->values()->all();
    }

    private function ask(string $text): array
    {
        $model = (string) config('services.ai_agents.chats.triage_model', 'claude-sonnet-5-5');
        $system = str_replace('{today}', now()->timezone('Europe/Kyiv')->format('Y-m-d (l)'), self::PROMPT);
        $started = microtime(true);
        try {
            $res = $this->gateway->step($model, 'low', $system, [['role' => 'user', 'content' => $text]], []);
        } catch (Throwable $e) {
            report($e);

            return ['agent' => null, 'clarify' => 'Не вдалося розібрати — спробуйте ще раз або скористайтесь кнопкою «Доручити агенту» на картці клієнта'];
        }
        $raw = collect($res['content'])->where('type', 'text')->pluck('text')->implode('');
        $json = json_decode(trim(preg_replace('/^```(json)?|```$/m', '', $raw)), true);
        AiRun::create([
            'purpose' => 'agent_router', 'model' => $res['model'] ?: $model, 'status' => is_array($json) ? 'ok' : 'invalid',
            'output' => is_array($json) ? $json : null,
            'input_tokens' => $res['usage']['input'], 'output_tokens' => $res['usage']['output'], 'cache_read_tokens' => $res['usage']['cache_read'],
            'cost_usd' => AgentLoop::costOf($res['model'] ?: $model, $res['usage']),
            'latency_ms' => (int) round((microtime(true) - $started) * 1000),
        ]);
        if (!is_array($json)) return ['agent' => null, 'clarify' => 'Не зрозумів доручення. Напишіть, кому й що зробити, напр. «склади меню Ковальовій на 27–31.10»'];

        foreach (['period_from', 'period_to'] as $k) {
            $json[$k] = !empty($json[$k]) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $json[$k]) ? Carbon::parse($json[$k])->toDateString() : null;
        }

        return $json;
    }
}
