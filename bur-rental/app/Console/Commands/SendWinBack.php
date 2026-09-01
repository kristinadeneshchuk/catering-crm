<?php

namespace App\Console\Commands;

use App\Services\WinBack;
use Illuminate\Console\Command;

/**
 * Розсилка на повернення. У розкладі раз на тиждень.
 */
class SendWinBack extends Command
{
    protected $signature = 'reminders:winback {--dry : показати, кому пішло б, і нічого не надсилати}';

    protected $description = 'Нагадати про себе клієнтам, які давно не орендували';

    public function handle(WinBack $winBack): int
    {
        if ($this->option('dry')) {
            $due = $winBack->due();

            foreach ($due as $client) {
                $this->line("{$client->display_phone} · остання оренда {$winBack->lastRent($client)}");
                $this->line('  '.$winBack->text($client));
            }

            $this->info("До відправки: {$due->count()}");

            return self::SUCCESS;
        }

        $this->info('Надіслано нагадувань: '.$winBack->send());

        return self::SUCCESS;
    }
}
