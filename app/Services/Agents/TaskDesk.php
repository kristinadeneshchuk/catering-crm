<?php

namespace App\Services\Agents;

use App\Jobs\RunAgentTask;
use App\Models\AiAgent;
use App\Models\AiAgentTask;
use App\Models\Order;
use App\Models\User;
use Carbon\Carbon;

/**
 * Задачі агентам від менеджера (docs/tz-ai-agents-in-crm.md §3): поставити з картки,
 * відповісти на питання, перезапустити, скасувати. Серверний агент стартує одразу
 * в черзі; агент на Маку забере задачу сам через API agents/v1.
 */
class TaskDesk
{
    public function assign(AiAgent $agent, User $by, array $data): AiAgentTask
    {
        $order = !empty($data['order_id']) ? Order::with('client')->find($data['order_id']) : null;
        $template = $data['template'] ?? 'free';
        $from = !empty($data['period_from']) ? Carbon::parse($data['period_from']) : null;
        $to = !empty($data['period_to']) ? Carbon::parse($data['period_to']) : $from;

        $task = $agent->tasks()->create([
            'title'       => $this->title($template, $order, $from, $to, $data['details'] ?? null),
            'details'     => $data['details'] ?? null,
            'template'    => $template,
            'client_id'   => $order?->client_id ?? ($data['client_id'] ?? null),
            'order_id'    => $order?->id,
            'period_from' => $from?->toDateString(),
            'period_to'   => $to?->toDateString(),
            'status'      => 'new',
            'created_by'  => $by->id,
            'due_at'      => $data['due_at'] ?? $this->deadline($template, $from),
        ]);
        $task->log('note', "Поставив(ла) {$by->name}", [], $by->id);

        if ($agent->runsOnServer()) RunAgentTask::dispatch($task);

        return $task;
    }

    public function answer(AiAgentTask $task, User $by, string $text): void
    {
        $task->log('answer', $text, [], $by->id);
        if ($task->agent->runsOnServer()) RunAgentTask::dispatch($task, $text);
    }

    public function restart(AiAgentTask $task, User $by, ?string $comment = null): void
    {
        $task->log('note', "Перезапустив(ла) {$by->name}" . ($comment ? ": {$comment}" : ''), [], $by->id);
        $task->forceFill(['status' => 'new', 'error' => null, 'finished_at' => null])->save();
        if ($task->agent->runsOnServer()) RunAgentTask::dispatch($task, $comment);
    }

    public function cancel(AiAgentTask $task, User $by): void
    {
        $task->log('note', "Скасував(ла) {$by->name}", [], $by->id);
        $task->forceFill(['status' => 'cancelled', 'finished_at' => now()])->save();
    }

    private function title(string $template, ?Order $order, ?Carbon $from, ?Carbon $to, ?string $details): string
    {
        $who = $order?->client?->name;
        $period = $from ? $from->format('d.m') . ($to && !$to->isSameDay($from) ? '–' . $to->format('d.m') : '') : null;
        $what = match ($template) {
            'menu_period'  => 'Меню',
            'replacements' => 'Заміни',
            'chat_check'   => 'Перевірити переписку',
            default        => mb_strimwidth((string) $details, 0, 80, '…') ?: 'Задача',
        };

        return trim(implode(' ', array_filter([$what, $who, $period])));
    }

    /** Меню на D — до 10:00 D−1; інше без дедлайну. */
    private function deadline(string $template, ?Carbon $from): ?Carbon
    {
        if (!$from || !in_array($template, ['menu_period', 'replacements'], true)) return null;

        return $from->copy()->subDay()->setTime((int) config('services.ai_agents.menu_deadline_hour', 10), 0);
    }
}
