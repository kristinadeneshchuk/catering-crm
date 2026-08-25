<?php

namespace App\Console\Commands;

use App\Services\ReturnReminders;
use Illuminate\Console\Command;

/**
 * Розсилка нагадувань про повернення. Крутиться в розкладі раз на добу.
 */
class SendReturnReminders extends Command
{
    protected $signature = 'reminders:returns {--dry : показати, кому пішло б, і нічого не надсилати}';

    protected $description = 'Нагадати клієнтам, що завтра повертати техніку';

    public function handle(ReturnReminders $reminders): int
    {
        if ($this->option('dry')) {
            $due = $reminders->due();

            foreach ($due as $booking) {
                $this->line("{$booking->number} · {$booking->phone}");
                $this->line('  '.$reminders->text($booking));
            }

            $this->info("Нагадувань до відправки: {$due->count()}");

            return self::SUCCESS;
        }

        $this->info('Надіслано нагадувань: '.$reminders->send());

        return self::SUCCESS;
    }
}
