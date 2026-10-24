<?php

namespace App\Jobs;

use App\Models\AiAgentTask;
use App\Services\Agents\Runtime\AgentLoop;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Задача агента в окремій черзі `agents` (своя програма supervisor), щоб довга
 * робота не блокувала накладні й Telegram. Відповідь менеджера будить ту саму задачу.
 */
class RunAgentTask implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 1800;

    public function __construct(public AiAgentTask $task, public ?string $answer = null)
    {
        $this->onQueue((string) config('services.ai_agents.runtime.queue', 'agents'));
    }

    public function handle(AgentLoop $loop): void
    {
        $loop->run($this->task, $this->answer);
    }
}
