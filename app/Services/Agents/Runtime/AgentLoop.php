<?php

namespace App\Services\Agents\Runtime;

use App\Models\AiAgentTask;
use App\Models\AiRun;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Цикл агента на сервері (docs/tz-ai-agents-in-crm.md §2): модель → інструмент →
 * результат → далі, доки агент не подасть пропозицію й не завершить задачу
 * або не спитає менеджера. Кожен крок — у тред задачі й у журнал витрат ai_runs.
 *
 * Розмова зберігається файлом (storage/app/agents/tasks/<id>.json), щоб після
 * відповіді менеджера продовжити з того самого місця.
 */
class AgentLoop
{
    /** $ за 1M токенів: вхід, вихід, читання кешу, запис кешу (5 хв = ×1.25 входу). */
    public const PRICES = [
        'claude-opus-5-5'   => ['in' => 4.0, 'out' => 20.0, 'cache_read' => 0.2, 'cache_write' => 5.0],
        'claude-sonnet-5-5' => ['in' => 2.0, 'out' => 10.0, 'cache_read' => 0.2, 'cache_write' => 2.5],
        'claude-haiku-5-5'  => ['in' => 0.1, 'out' => 0.5, 'cache_read' => 0.01, 'cache_write' => 0.125],
    ];

    public function __construct(private ModelGateway $gateway) {}

    public function run(AiAgentTask $task, ?string $answer = null): void
    {
        $lock = Cache::lock("ai-agent-task-{$task->id}", 1800);
        if (!$lock->get()) return; // уже крутиться в іншому процесі

        try {
            $this->loop($task->fresh(['agent']), $answer);
        } catch (Throwable $e) {
            report($e);
            $this->fail($task, 'Збій: ' . mb_substr($e->getMessage(), 0, 300));
        } finally {
            $lock->release();
        }
    }

    private function loop(AiAgentTask $task, ?string $answer): void
    {
        if (!in_array($task->status, ['new', 'in_progress', 'waiting_human'], true)) return;
        $cfg = $this->config($task->agent->slug);
        $tools = $this->tools($task);
        $state = $this->load($task);

        if (!$state['messages']) {
            $state['messages'][] = ['role' => 'user', 'content' => $this->brief($task)];
            $task->log('step', 'Взяв задачу в роботу');
        } elseif ($state['pending'] !== null) {
            // Агент питав — віддаємо відповідь як результат того самого виклику.
            if ($answer === null) return;
            $results = $state['pending']['results'];
            $results[] = ['type' => 'tool_result', 'tool_use_id' => $state['pending']['tool_use_id'], 'content' => "Відповідь менеджера: {$answer}"];
            $state['messages'][] = ['role' => 'user', 'content' => $results];
            $state['pending'] = null;
        } else {
            // Перезапуск після збою посеред кроку: виклики без результатів закриваємо.
            $last = end($state['messages']) ?: [];
            $open = ($last['role'] ?? null) === 'assistant' ? array_filter((array) $last['content'], fn ($b) => ($b['type'] ?? null) === 'tool_use') : [];
            if ($open) {
                $state['messages'][] = ['role' => 'user', 'content' => array_values(array_map(fn ($b) => ['type' => 'tool_result', 'tool_use_id' => $b['id'], 'content' => 'Перервано збоєм — повторіть, якщо потрібно.', 'is_error' => true], $open))];
            }
            if ($answer !== null) {
                $state['messages'][] = ['role' => 'user', 'content' => "Повідомлення менеджера: {$answer}"];
            } elseif (!$open && ($last['role'] ?? null) === 'assistant') {
                $state['messages'][] = ['role' => 'user', 'content' => 'Продовжуй задачу.'];
            }
        }

        $task->forceFill(['status' => 'in_progress', 'started_at' => $task->started_at ?? now(), 'error' => null])->save();
        $system = $this->system($task);
        $nudged = false;

        while (true) {
            if ($task->steps >= $cfg['max_steps']) {
                $this->save($task, $state);
                $this->fail($task, "Ліміт кроків ({$cfg['max_steps']}) вичерпано — перевірте тред і перезапустіть або доробіть вручну");

                return;
            }
            if ($this->capReached()) {
                $this->save($task, $state);
                $this->fail($task, 'Денна стеля витрат на агентів вичерпана (AGENTS_DAILY_CAP_USD) — продовжить після перезапуску завтра');

                return;
            }

            $started = microtime(true);
            $res = $this->gateway->step($cfg['model'], $cfg['effort'], $system, $state['messages'], $tools->definitions());
            $cost = $this->cost($res['model'] ?: $cfg['model'], $res['usage']);
            $this->logRun($task, $cfg['model'], $res, $cost, $started);
            $task->forceFill(['steps' => $task->steps + 1, 'cost_usd' => round((float) $task->cost_usd + $cost, 4)])->save();

            $state['messages'][] = ['role' => 'assistant', 'content' => $res['content']];
            $uses = array_values(array_filter($res['content'], fn ($b) => ($b['type'] ?? null) === 'tool_use'));

            if ($res['stop_reason'] === 'refusal') {
                $this->save($task, $state);
                $this->fail($task, 'Модель відмовилась виконувати запит');

                return;
            }
            if (!$uses) {
                $text = trim(collect($res['content'])->where('type', 'text')->pluck('text')->implode("\n"));
                // Модель замовкла без finish: один раз нагадуємо, далі — вважаємо завершеним.
                if (!$nudged && $res['stop_reason'] !== 'max_tokens') {
                    $nudged = true;
                    if ($text !== '') $task->log('note', mb_substr($text, 0, 2000));
                    $state['messages'][] = ['role' => 'user', 'content' => 'Якщо задачу виконано — виклич finish з підсумком. Якщо ні — продовжуй інструментами або спитай менеджера (ask_manager).'];
                    continue;
                }
                $this->save($task, $state);
                $this->finish($task, $text !== '' ? $text : 'Агент зупинився без підсумку');

                return;
            }

            $results = [];
            foreach ($uses as $i => $use) {
                $name = (string) $use['name'];
                $input = (array) ($use['input'] ?? []);
                $task->log('tool', $this->describe($name, $input), ['tool' => $name, 'input' => $this->short($input)]);

                try {
                    $out = $tools->call($name, $input);
                } catch (Throwable $e) {
                    report($e);
                    $out = ToolResult::error('Помилка інструменту: ' . mb_substr($e->getMessage(), 0, 300));
                }

                if ($out->kind === 'pause') {
                    // Решта викликів цього кроку вже виконані — їхні результати віддамо разом із відповіддю.
                    $state['pending'] = ['tool_use_id' => $use['id'], 'results' => $results];
                    foreach (array_slice($uses, $i + 1) as $skip) {
                        $state['pending']['results'][] = ['type' => 'tool_result', 'tool_use_id' => $skip['id'], 'content' => 'Не виконано: спершу відповідь менеджера.', 'is_error' => true];
                    }
                    $this->save($task, $state);
                    $task->log('question', (string) $out->data);
                    $task->forceFill(['status' => 'waiting_human'])->save();
                    app(TaskNotifier::class)->question($task, (string) $out->data);

                    return;
                }
                if ($out->kind === 'finish') {
                    $state['messages'][] = ['role' => 'user', 'content' => array_merge($results, [['type' => 'tool_result', 'tool_use_id' => $use['id'], 'content' => $out->forModel()]])];
                    $this->save($task, $state);
                    $this->finish($task, (string) $out->data);

                    return;
                }

                $block = ['type' => 'tool_result', 'tool_use_id' => $use['id'], 'content' => $out->forModel()];
                if ($out->kind === 'error') $block['is_error'] = true;
                $results[] = $block;
            }

            $state['messages'][] = ['role' => 'user', 'content' => $results];
            $this->save($task, $state);
        }
    }

    /** @return array{model: string, effort: string, max_steps: int} */
    private function config(string $slug): array
    {
        $all = (array) config('services.ai_agents.runtime.agents', []);
        $c = $all[$slug] ?? [];

        return [
            'model' => (string) ($c['model'] ?? 'claude-opus-5-5'),
            'effort' => (string) ($c['effort'] ?? 'high'),
            'max_steps' => (int) ($c['max_steps'] ?? 40),
        ];
    }

    private function tools(AiAgentTask $task): AgentTools
    {
        $class = (array) config('services.ai_agents.runtime.tools', []);
        $tools = $class[$task->agent->slug] ?? MenuTools::class;

        return new $tools($task);
    }

    private function system(AiAgentTask $task): string
    {
        $base = @file_get_contents(resource_path("agents/{$task->agent->slug}.md")) ?: '';
        $rules = $task->agent->memory()->where('key', 'like', 'rules:%')->orderBy('key')->get(['key', 'value'])
            ->map(fn ($m) => '- ' . substr($m->key, 6) . ': ' . (is_string($m->value) ? $m->value : json_encode($m->value, JSON_UNESCAPED_UNICODE)))
            ->implode("\n");

        return trim($base
            . ($task->agent->instructions ? "\n\n## Правила власника\n" . $task->agent->instructions : '')
            . ($rules ? "\n\n## Памʼять: рішення власника\n" . $rules : ''));
    }

    private function brief(AiAgentTask $task): string
    {
        $parts = ["Задача #{$task->id}: {$task->title}"];
        if ($task->details) $parts[] = "Деталі від менеджера: {$task->details}";
        if ($task->period_from) $parts[] = 'Період: ' . $task->period_from->format('d.m.Y') . ' – ' . ($task->period_to?->format('d.m.Y') ?? '?');
        if ($task->order_id) $parts[] = "Замовлення #{$task->order_id}";
        $parts[] = 'Почни з task_context.';

        return implode("\n", $parts);
    }

    /** Людський рядок у тред замість сирого виклику. */
    private function describe(string $name, array $input): string
    {
        return match ($name) {
            'task_context'     => 'Читаю картку клієнта, замовлення й виключення',
            'search_dishes'    => 'Шукаю страви' . (!empty($input['q']) ? " «{$input['q']}»" : '') . (!empty($input['ingredient']) ? " з «{$input['ingredient']}»" : ''),
            'dish_composition' => 'Перевіряю склад страви #' . ($input['dish_id'] ?? '?'),
            'find_ingredients' => 'Шукаю продукт «' . ($input['q'] ?? '') . '»',
            'check_menu'       => 'Перевіряю чернетку меню (' . count((array) ($input['days'] ?? [])) . ' дн.)',
            'submit_menu'      => 'Подаю меню на погодження',
            'submit_replacements' => 'Подаю заміни на погодження',
            'remember'         => 'Запамʼятовую: ' . ($input['key'] ?? ''),
            default            => $name,
        };
    }

    /** Великі payload у тред не кладемо цілком. */
    private function short(array $input): array
    {
        $json = json_encode($input, JSON_UNESCAPED_UNICODE);

        return strlen((string) $json) > 4000 ? ['_truncated' => mb_substr((string) $json, 0, 4000)] : $input;
    }

    private function cost(string $model, array $u): float
    {
        $p = self::PRICES[$model] ?? self::PRICES['claude-opus-5-5'];

        return round(($u['input'] * $p['in'] + $u['output'] * $p['out'] + $u['cache_read'] * $p['cache_read'] + ($u['cache_write'] ?? 0) * $p['cache_write']) / 1_000_000, 4);
    }

    private function capReached(): bool
    {
        $cap = (float) config('services.ai_agents.runtime.daily_cap_usd', 20);

        return $cap > 0 && (float) AiRun::where('purpose', 'like', 'agent_%')->whereDate('created_at', now()->toDateString())->sum('cost_usd') >= $cap;
    }

    private function logRun(AiAgentTask $task, string $model, array $res, float $cost, float $started): void
    {
        AiRun::create([
            'purpose' => 'agent_' . $task->agent->slug,
            'model' => $res['model'] ?: $model,
            'status' => 'ok',
            'subject_type' => AiAgentTask::class,
            'subject_id' => $task->id,
            'input_meta' => ['step' => $task->steps + 1, 'stop_reason' => $res['stop_reason']],
            'input_tokens' => $res['usage']['input'],
            'output_tokens' => $res['usage']['output'],
            'cache_read_tokens' => $res['usage']['cache_read'],
            'cost_usd' => $cost,
            'latency_ms' => (int) round((microtime(true) - $started) * 1000),
        ]);
    }

    private function finish(AiAgentTask $task, string $summary): void
    {
        $task->log('note', mb_substr($summary, 0, 4000));
        $task->forceFill(['status' => 'done', 'result' => $summary, 'finished_at' => now()])->save();
        app(TaskNotifier::class)->finished($task);
    }

    private function fail(AiAgentTask $task, string $reason): void
    {
        $task->log('error', $reason);
        $task->forceFill(['status' => 'failed', 'error' => $reason, 'finished_at' => now()])->save();
        app(TaskNotifier::class)->failed($task, $reason);
    }

    /** @return array{messages: array, pending: ?array} */
    private function load(AiAgentTask $task): array
    {
        $raw = Storage::disk('local')->get($this->path($task));
        $state = $raw ? json_decode($raw, true) : null;

        return ['messages' => $state['messages'] ?? [], 'pending' => $state['pending'] ?? null];
    }

    private function save(AiAgentTask $task, array $state): void
    {
        Storage::disk('local')->put($this->path($task), json_encode($state, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
    }

    private function path(AiAgentTask $task): string
    {
        return "agents/tasks/{$task->id}.json";
    }
}
