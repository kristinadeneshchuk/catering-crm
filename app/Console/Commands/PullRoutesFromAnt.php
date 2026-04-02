<?php

namespace App\Console\Commands;

use App\Services\AntLogisticsService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class PullRoutesFromAnt extends Command
{
    protected $signature   = 'ant:pull-routes {date? : Дата у форматі Y-m-d (за замовчуванням — завтра)}';
    protected $description = 'Тягнемо маршрути з Ant Logistics і зберігаємо у order_days';

    public function handle(AntLogisticsService $service): int
    {
        $date = $this->argument('date')
            ? Carbon::parse($this->argument('date'))->format('Y-m-d')
            : now()->addDay()->format('Y-m-d');

        $this->info("[AntLogistics] Завантажуємо маршрути на {$date}...");

        try {
            $count = $service->pullRouteAssignments($date);
            $this->info("[AntLogistics] Оновлено точок: {$count}");
            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('[AntLogistics] Помилка: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }
}
