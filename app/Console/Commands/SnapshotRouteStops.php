<?php

namespace App\Console\Commands;

use App\Models\RouteStop;
use App\Services\AntLogisticsService;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * Знімає з ANT маршрути й точки за день і кладе в архів.
 *
 * Страховка на випадок, коли менеджер не натиснув «Точки маршрутів» вручну.
 * Ідемпотентна: маршрути йдуть через updateOrCreate, точки — теж, і чистка
 * зачіпає лише ті зміни, які ANT цього разу віддав.
 *
 * Береться вчорашній день: до ранку логіст уже точно все розвіз і перебудовувати
 * маршрути заднім числом нікому. Це те саме «наступного дня, коли вже все
 * розвезли», про яке говорив Тарас.
 */
class SnapshotRouteStops extends Command
{
    protected $signature = 'routes:snapshot {date? : дата доставки (Y-m-d), за замовчуванням учора}';
    protected $description = 'Зберігає маршрути і точки дня в архів route_stops';

    public function handle(AntLogisticsService $ant): int
    {
        $date = $this->argument('date')
            ? Carbon::parse($this->argument('date'))->format('Y-m-d')
            : now()->subDay()->format('Y-m-d');

        $before = RouteStop::whereDate('date', $date)->count();

        try {
            // Спершу шапки — знімок точок бере з них курʼєра й авто.
            $routes = $ant->pullRouteDetails($date, 'all');
            $ant->pullRouteAssignments($date, 'all');
        } catch (\Throwable $e) {
            $this->error("[RouteSnapshot] {$date}: " . $e->getMessage());
            report($e);

            return self::FAILURE;
        }

        $after = RouteStop::whereDate('date', $date)->count();

        $this->info("[RouteSnapshot] {$date}: маршрутів {$routes}, точок в архіві {$after} (було {$before})");

        return self::SUCCESS;
    }
}
