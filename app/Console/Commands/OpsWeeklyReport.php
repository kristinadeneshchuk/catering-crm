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
    protected $signature = 'ops:weekly-report
        {--from= : Понеділок тижня, за замовчуванням минулий}
        {--to= : Надіслати лише цьому Telegram ID (за замовчуванням — усім власникам)}
        {--dry : Показати в консолі}';

    protected $description = 'Тижневий звіт операційного директора власникам';

    public function handle(WeeklyReport $report, TelegramService $telegram): int
    {
        $from = $this->option('from')
            ? Carbon::parse($this->option('from'))->startOfWeek()
            : now()->subWeek()->startOfWeek();
        $to = $from->copy()->endOfWeek();

        $messages   = $report->build($from, $to);
        $recipients = $this->option('to')
            ? [(string) $this->option('to')]
            : config('ops.weekly_report_to', []); // порожньо — усім власникам

        foreach ($messages as $text) {
            if ($this->option('dry')) {
                $this->line(strip_tags($text));
                $this->line('');

                continue;
            }

            if ($recipients === []) {
                $telegram->sendToOwner($text);

                continue;
            }

            foreach ($recipients as $chatId) {
                $telegram->sendMessage($chatId, $text);
            }
        }

        $this->info(count($messages).' повідомлень'.($this->option('dry') ? ' (не відправлено)'
            : ($recipients !== [] ? ' надіслано '.implode(', ', $recipients) : ' надіслано власникам')));

        return self::SUCCESS;
    }
}
