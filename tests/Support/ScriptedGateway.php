<?php

namespace Tests\Support;

use App\Services\Agents\Runtime\ModelGateway;

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
        // Рядок замість списку викликів — текстова відповідь (напр. JSON сортування).
        if (is_string($uses)) {
            return ['content' => [['type' => 'text', 'text' => $uses]], 'stop_reason' => 'end_turn', 'model' => $model,
                'usage' => ['input' => 1000, 'output' => 50, 'cache_read' => 0, 'cache_write' => 0]];
        }

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
