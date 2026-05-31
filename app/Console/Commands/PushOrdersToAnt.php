<?php

namespace App\Console\Commands;

use App\Services\AntLogisticsService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class PushOrdersToAnt extends Command
{
    protected $signature = 'ant:push-orders
                            {date?  : Target date in Y-m-d format (default: tomorrow)}
                            {shift? : Shift to push — morning | evening | all (default: all)}';

    protected $description = 'Push daily orders to Ant Logistics as Заявки';

    public function handle(AntLogisticsService $ant): int
    {
        $date  = $this->argument('date')  ?? Carbon::tomorrow()->format('Y-m-d');
        $shift = $this->argument('shift') ?? 'all';

        if (!in_array($shift, ['morning', 'evening', 'all'], true)) {
            $this->error("Invalid shift \"{$shift}\". Allowed values: morning, evening, all.");
            return self::FAILURE;
        }

        $this->info("Pushing orders to Ant for date={$date}, shift={$shift} ...");

        try {
            $result  = $ant->pushDailyOrders($date, $shift);
            $pushed  = (int) ($result['pushed'] ?? 0);
            $total   = (int) ($result['total']  ?? 0);
            $failed  = (int) ($result['failed'] ?? 0);
            $skipped = $result['skipped'] ?? [];

            $this->info("Done. {$pushed}/{$total} Заявки pushed.");
            if ($failed > 0) {
                $this->warn("Rejected by Ant: {$failed}");
            }
            if (!empty($skipped)) {
                $this->warn('Skipped clients (multiple addresses without default):');
                foreach ($skipped as $s) {
                    $this->warn("  - {$s['client_name']} (id={$s['client_id']})");
                }
            }
            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('Failed to push orders: ' . $e->getMessage());
            return self::FAILURE;
        }
    }
}
