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
            $count = $ant->pushDailyOrders($date, $shift);
            $this->info("Done. {$count} Заявки pushed to Ant Logistics.");
            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('Failed to push orders: ' . $e->getMessage());
            return self::FAILURE;
        }
    }
}
