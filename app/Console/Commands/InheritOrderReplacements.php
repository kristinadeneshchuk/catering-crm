<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Services\Orders\ReplacementInheritance;
use Illuminate\Console\Command;

/**
 * Разове донаповнення: поточні замовлення, що створились без замін клієнта.
 *
 *   orders:inherit-replacements           — лише показує, що скопіює
 *   orders:inherit-replacements --apply   — копіює
 */
class InheritOrderReplacements extends Command
{
    protected $signature = 'orders:inherit-replacements {--apply : Записати заміни}';

    protected $description = 'Скопіювати заміни з попереднього замовлення клієнта в поточні замовлення без замін';

    public function handle(ReplacementInheritance $inheritance): int
    {
        $apply = (bool) $this->option('apply');

        $orders = Order::query()
            ->whereIn('status', ['new', 'active', 'paused'])
            ->whereNull('parent_order_id')
            ->whereDoesntHave('replacements')
            ->with('client')
            ->orderBy('id')
            ->get();

        $rows = [];
        foreach ($orders as $order) {
            $source = $inheritance->sourceFor($order);
            $count = $source?->replacements()->count() ?? 0;
            if (! $count) {
                continue;
            }

            if ($apply) {
                $inheritance->inherit($order);
            }
            $rows[] = [$order->id, $order->client?->name, $order->start_date?->format('d.m').'–'.$order->end_date?->format('d.m'), $source->id, $count];
        }

        $this->table(['Замовлення', 'Клієнт', 'Дати', 'Звідки', 'Замін'], $rows);
        $this->info(($apply ? 'Скопійовано' : 'Буде скопійовано').': '.count($rows).' замовл., '.array_sum(array_column($rows, 4)).' замін.');

        return self::SUCCESS;
    }
}
