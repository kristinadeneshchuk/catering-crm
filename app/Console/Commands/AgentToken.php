<?php

namespace App\Console\Commands;

use App\Models\AiAgent;
use Illuminate\Console\Command;

/** Видати (перевипустити) токен агенту. Показується один раз. */
class AgentToken extends Command
{
    protected $signature = 'agents:token {slug}';

    protected $description = 'Новий API-токен для ШІ-агента (старий перестає діяти)';

    public function handle(): int
    {
        $agent = AiAgent::where('slug', $this->argument('slug'))->first();
        if (!$agent) {
            $this->error('Агента не знайдено.');
            return self::FAILURE;
        }

        $this->line($agent->issueToken());

        return self::SUCCESS;
    }
}
