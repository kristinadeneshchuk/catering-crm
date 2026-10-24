<?php

namespace App\Console\Commands;

use App\Jobs\ProcessInboxChat;
use App\Models\AiAgent;
use App\Services\Inbox\InboxReadClient;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * Звірка з Inbox на випадок, коли вебхук мовчав (простій CRM, повтори вичерпались):
 * діалоги, де клієнт писав після останньої звірки, — у чергу агента «Переписки».
 */
class AgentsInboxSync extends Command
{
    protected $signature = 'agents:inbox-sync';

    protected $description = 'Догнати нові повідомлення клієнтів з Inbox (резерв до вебхука)';

    public function handle(InboxReadClient $inbox): int
    {
        if (!config('services.ai_agents.chats.enabled') || !$inbox->configured()) return self::SUCCESS;

        $agent = AiAgent::serverChats();
        $since = Carbon::parse($agent->memory()->where('key', 'inbox:sync_since')->value('value') ?? now()->subHour());
        $started = now();
        $n = 0;
        foreach ($inbox->conversationsUpdatedSince($since, 500) as $row) {
            $c = (array) ($row['conversation'] ?? []);
            if (empty($c['last_client_message_at']) || Carbon::parse($c['last_client_message_at'])->lt($since)) continue;
            if (($row['contact']['kind'] ?? 'client') !== 'client' || ($c['chat_type'] ?? 'private') !== 'private') continue;
            ProcessInboxChat::dispatch((int) $c['id']);
            $n++;
        }
        $agent->memory()->updateOrCreate(['key' => 'inbox:sync_since'], ['value' => $started->toIso8601ZuluString()]);
        $this->info("У черзі: {$n}");

        return self::SUCCESS;
    }
}
