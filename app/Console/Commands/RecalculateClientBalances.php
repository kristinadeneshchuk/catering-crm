<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Client;
use App\Models\Transaction;

class RecalculateClientBalances extends Command
{
    protected $signature = 'app:recalculate-balances {--dry-run : Тільки показати клієнтів з некоректним балансом, нічого не змінювати}';

    protected $description = 'Перераховує баланси клієнтів за формулою SUM(income)-SUM(refund)-SUM(orders.final_price) та оновлює FIFO статуси оплати';

    public function handle()
    {
        $dryRun = (bool) $this->option('dry-run');

        $this->info($dryRun ? 'DRY-RUN: показую кого треба правити, нічого не змінюю...' : 'Перерахунок балансів...');

        $changed = 0;
        $total   = 0;

        Client::chunk(100, function ($clients) use ($dryRun, &$changed, &$total) {
            foreach ($clients as $client) {
                $total++;

                $orderIds   = $client->orders()->pluck('id');
                $income     = (float) Transaction::whereIn('order_id', $orderIds)->where('type', 'income')->sum('amount');
                $refund     = (float) Transaction::whereIn('order_id', $orderIds)->where('type', 'refund')->sum('amount');
                $ordersCost = (float) $client->orders()->sum('final_price');

                $expected = round($income - $refund - $ordersCost, 2);
                $current  = round((float) $client->balance, 2);

                if (abs($expected - $current) < 0.01) {
                    continue;
                }

                $changed++;
                $diff = $expected - $current;
                $this->line(sprintf(
                    '#%d %s: %.2f → %.2f (diff %+.2f)',
                    $client->id,
                    $client->name,
                    $current,
                    $expected,
                    $diff
                ));

                if (!$dryRun) {
                    $client->syncBalance();
                    $client->recalculateOrderPaymentStatus();
                }
            }
        });

        $this->info(sprintf('Готово. Перевірено клієнтів: %d, з некоректним балансом: %d', $total, $changed));

        if ($dryRun && $changed > 0) {
            $this->warn('Це був DRY-RUN. Щоб реально виправити — запусти без --dry-run.');
        }

        return self::SUCCESS;
    }
}
