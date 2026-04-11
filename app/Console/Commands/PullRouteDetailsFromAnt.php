<?php

namespace App\Console\Commands;

use App\Services\AntLogisticsService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class PullRouteDetailsFromAnt extends Command
{
    protected $signature = 'ant:pull-route-details {date? : Дата маршруту (Y-m-d), за замовчуванням сьогодні} {shift? : morning|evening|all}';
    protected $description = 'Тягне деталі маршрутів з АНТ: км, точки, паливо, авто, розраховує ставку кур\'єра';

    public function handle(AntLogisticsService $service): int
    {
        $date  = $this->argument('date') ?: Carbon::today()->format('Y-m-d');
        $shift = $this->argument('shift') ?: 'all';

        $this->info("[AntLogistics] Тягнемо деталі маршрутів за {$date} (зміна: {$shift})...");

        try {
            $count = $service->pullRouteDetails($date, $shift);
            $this->info("[AntLogistics] Збережено маршрутів: {$count}");
            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('[AntLogistics] Помилка: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }
}
