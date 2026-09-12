<?php

namespace App\Console\Commands;

use App\Services\Couriers\CourierReportService;
use Illuminate\Console\Command;

/**
 * Розсилає курʼєрам заповнений шаблон звіту зміни (або нагадування).
 *
 * Шаблон іде на початку зміни — коли маршрути вже побудовані й привʼязані до
 * курʼєрів. Нагадування — наприкінці, тим, хто ще не здав.
 */
class SendCourierShiftTemplates extends Command
{
    protected $signature = 'couriers:shift-report
                            {slot : morning | evening}
                            {--remind : надіслати нагадування замість шаблону}
                            {--date= : дата доставки (Y-m-d), за замовчуванням сьогодні}';

    protected $description = 'Шаблон звіту зміни курʼєрам у Telegram (або нагадування)';

    public function handle(CourierReportService $reports): int
    {
        $slot = (string) $this->argument('slot');

        if (! in_array($slot, ['morning', 'evening'], true)) {
            $this->error('slot — morning або evening');

            return self::FAILURE;
        }

        $date = $this->option('date') ?: now()->toDateString();

        $count = $this->option('remind')
            ? $reports->remind($date, $slot)
            : $reports->sendTemplates($date, $slot);

        $this->info(($this->option('remind') ? 'Нагадувань' : 'Шаблонів')." надіслано: {$count} ({$date}, {$slot})");

        return self::SUCCESS;
    }
}
