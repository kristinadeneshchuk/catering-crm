<?php

namespace App\Jobs;

use App\Services\Agents\ChatTriage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/** Нове в чаті Inbox → сортування → задача агенту «Переписки». Один на чат за раз. */
class ProcessInboxChat implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;
    public array $backoff = [60, 300];
    public int $uniqueFor = 900;

    public function __construct(public int $conversationId)
    {
        $this->onQueue((string) config('services.ai_agents.runtime.queue', 'agents'));
    }

    public function uniqueId(): string
    {
        return (string) $this->conversationId;
    }

    public function handle(ChatTriage $triage): void
    {
        $triage->run($this->conversationId);
    }
}
