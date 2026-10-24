<?php

namespace App\Services\Agents\Runtime;

/**
 * Що повернув інструмент: дані для моделі, помилку, або сигнал циклу —
 * пауза (агент спитав людину) чи кінець задачі.
 */
final class ToolResult
{
    private function __construct(
        public readonly string $kind,   // ok / error / pause / finish
        public readonly mixed $data,
    ) {}

    public static function ok(mixed $data): self { return new self('ok', $data); }
    public static function error(mixed $data): self { return new self('error', $data); }
    public static function pause(string $question): self { return new self('pause', $question); }
    public static function finish(string $summary): self { return new self('finish', $summary); }

    public function forModel(): string
    {
        return match ($this->kind) {
            'pause'  => 'Питання передано менеджеру. Робота на паузі до відповіді.',
            'finish' => 'Задачу завершено.',
            default  => is_string($this->data) ? $this->data : json_encode($this->data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
        };
    }
}
