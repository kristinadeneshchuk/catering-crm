<?php

namespace App\Console\Commands;

use App\Models\Employee;
use App\Models\EmployeeShift;
use App\Services\CourierShiftPricingService;
use Illuminate\Console\Command;

class RepriceCourierShifts extends Command
{
    protected $signature = 'shifts:reprice-couriers {--all : Прогнати геть усі зміни, а не лише нульові}';
    protected $description = 'Переоцінити ставку кур\'єрських змін виходячи з поточних маршрутів (фіксить випадки, коли галочку у Табелі поставили до імпорту з ANT)';

    public function handle(CourierShiftPricingService $pricing): int
    {
        $courierIds = Employee::where('position', 'courier')->pluck('id');
        if ($courierIds->isEmpty()) {
            $this->warn('Курʼєрів не знайдено.');
            return self::SUCCESS;
        }

        $query = EmployeeShift::whereIn('employee_id', $courierIds);
        if (! $this->option('all')) {
            $query->where('rate', 0);
        }

        $shifts = $query->orderBy('date')->get(['id', 'employee_id', 'date', 'rate']);
        $this->info("Перевіряю " . $shifts->count() . " змін…");

        $touched = 0;
        foreach ($shifts as $s) {
            $date = $s->date instanceof \Carbon\Carbon ? $s->date->format('Y-m-d') : (string) $s->date;
            if ($pricing->reprice((int) $s->employee_id, $date)) {
                $touched++;
                $this->line("  ✓ emp={$s->employee_id} date={$date} rate {$s->rate} → переоцінено");
            }
        }

        $this->info("Готово. Оновлено: {$touched}.");
        return self::SUCCESS;
    }
}
