<?php

namespace App\Services\Agents\Runtime;

use App\Models\AiAgentTask;
use App\Services\Agents\ProposalDesk;
use App\Services\TelegramService;
use Throwable;

/**
 * Сповіщення в Telegram тому, хто поставив задачу (AI_APPROVERS: user_id → Telegram id);
 * якщо його немає в списку — власникам. Реплай на питання = відповідь агенту.
 */
class TaskNotifier
{
    public function __construct(private TelegramService $telegram) {}

    public function question(AiAgentTask $task, string $question): void
    {
        $this->send($task, "❓ {$task->agent->name} питає по задачі «{$task->title}»:\n{$question}\n\nВідповісти: " . $this->link($task));
    }

    public function finished(AiAgentTask $task): void
    {
        if (!$task->proposals()->where('status', 'pending')->exists()) return; // нема що погоджувати — не турбуємо
        $this->send($task, "✅ {$task->agent->name}: «{$task->title}» — готово, чекає погодження.\n" . $this->link($task));
    }

    public function failed(AiAgentTask $task, string $reason): void
    {
        $this->send($task, "⚠️ {$task->agent->name}: «{$task->title}» — зупинився.\n{$reason}\n" . $this->link($task));
    }

    public function link(AiAgentTask $task): string
    {
        return rtrim((string) config('app.url'), '/') . "/admin/ai-agent-tasks/{$task->id}";
    }

    private function send(AiAgentTask $task, string $text): void
    {
        try {
            $chat = $task->created_by ? (app(ProposalDesk::class)->approvers()[$task->created_by] ?? null) : null;
            $chat ? $this->telegram->sendMessage($chat, $text) : $this->telegram->sendToOwner($text);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
