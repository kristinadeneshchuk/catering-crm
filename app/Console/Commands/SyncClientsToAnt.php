<?php

namespace App\Console\Commands;

use App\Services\AntLogisticsService;
use Illuminate\Console\Command;

class SyncClientsToAnt extends Command
{
    protected $signature = 'ant:sync-clients';
    protected $description = 'Sync all active clients to Ant Logistics as Торгові точки (Comps)';

    public function handle(AntLogisticsService $ant): int
    {
        $this->info('Starting Ant Logistics client sync...');

        try {
            $ant->syncAllClients();
            $this->info('Client sync completed successfully.');
            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('Client sync failed: ' . $e->getMessage());
            return self::FAILURE;
        }
    }
}
