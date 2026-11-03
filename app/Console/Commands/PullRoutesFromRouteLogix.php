<?php

namespace App\Console\Commands;

use App\Services\RouteLogixService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class PullRoutesFromRouteLogix extends Command
{
    protected $signature   = 'routelogix:pull
                                {date?  : Дата доставки Y-m-d (за замовчуванням — завтра)}
                                {shift? : morning | evening (за замовчуванням — morning)}
                                {--preview : Лише показати, нічого не записувати}';
    protected $description = 'Маршрути зміни з RouteLogix → order_days (наліпки) і delivery_routes (виїзди)';

    public function handle(RouteLogixService $service): int
    {
        $date  = Carbon::parse($this->argument('date') ?? now()->addDay())->format('Y-m-d');
        $shift = $this->argument('shift') ?? 'morning';

        try {
            $r = $service->import($date, $shift, (bool) $this->option('preview'));
        } catch (\Throwable $e) {
            $this->error('[RouteLogix] ' . $e->getMessage());
            return Command::FAILURE;
        }

        $this->info("[RouteLogix] {$date} {$shift}: маршрутів {$r['routes']}, адрес {$r['stops']}, днів {$r['days']}");

        if ($r['unknown_days']) {
            $this->warn('Не наші на цю дату/зміну order_day_ids: ' . implode(', ', $r['unknown_days']));
        }
        foreach ($r['unmatched_couriers'] as $num => $driver) {
            $this->warn("Маршрут №{$num}: курʼєра «{$driver}» не знайдено серед співробітників");
        }

        if (! $r['written']) {
            $this->line($service->enabled() ? 'Перегляд — нічого не записано.' : 'LOGIST_LABELS=false — нічого не записано.');
            return Command::SUCCESS;
        }

        $this->info("Записано. Знято з маршрутів днів: {$r['cleared']}, прибрано маршрутів ANT: {$r['ant_removed']}");

        return Command::SUCCESS;
    }
}
