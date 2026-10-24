<?php

namespace App\Services\Agents\Runtime;

/**
 * Один крок моделі для агента. Окремим контрактом — щоб у тестах підставити
 * сценарій замість мережі.
 */
interface ModelGateway
{
    /**
     * @param  array<int, array<string, mixed>>  $messages  у форматі Messages API (snake_case)
     * @param  array<int, array<string, mixed>>  $tools
     * @return array{content: array<int, array<string, mixed>>, stop_reason: ?string, usage: array{input: int, output: int, cache_read: int, cache_write: int}, model: string}
     */
    public function step(string $model, string $effort, string $system, array $messages, array $tools): array;
}
