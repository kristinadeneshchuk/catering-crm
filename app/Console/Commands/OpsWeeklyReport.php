<?php

namespace App\Console\Commands;

use App\Services\Ops\WeeklyReport;
use App\Services\TelegramService;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * Тижневий звіт операційного директора: щопонеділка о 09:00 власникам у Telegram.
 * За минулий тиждень (пн–нд). Кожен розділ — окреме повідомлення.
 */
class OpsWeeklyReport extends Command
{
    protected $signature = 'ops:weekly-report {--from= : Понеділок тижня, за замовчуванням минулий} {--dry : Показати в консолі}';

    protected $description = 'Тижневий звіт операційного директора власникам';

    public function handle(WeeklyReport $report, TelegramService $telegram): int
    {
        $from = $this->option('from')
            ? Carbon::parse($this->option('from'))->startOfWeek()
            : now()->subWeek()->startOfWeek();
        $to = $from->copy()->endOfWeek();

        $messages = $report->build($from, $to);

        foreach ($messages as $text) {
            if ($this->option('dry')) {
                $this->line(strip_tags($text));
                $this->line('');

                continue;
            }

            $telegram->sendToOwner($text);
        }

        $this->info(count($messages).' повідомлень'.($this->option('dry') ? ' (не відправлено)' : ' надіслано власникам'));

        return self::SUCCESS;
    }
}
