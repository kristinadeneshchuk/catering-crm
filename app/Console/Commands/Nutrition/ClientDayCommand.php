<?php

namespace App\Console\Commands\Nutrition;

use App\Models\Client;
use App\Models\MenuPlan;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

/**
 * READ-ONLY. Resolve client's effective DailyMenu for a date and run analyze on it.
 */
class ClientDayCommand extends Command
{
    protected $signature = 'nutrition:client:day {clientId : Client ID} {date : YYYY-MM-DD} {--json : Machine-readable output}';

    protected $description = 'Read-only: client effective day menu (via active order) + analyze under client target';

    public function handle(): int
    {
        $client = Client::find($this->argument('clientId'));
        if (!$client) {
            $this->error("Клиент #{$this->argument('clientId')} не найден.");
            return self::FAILURE;
        }

        try {
            $date = Carbon::parse($this->argument('date'));
        } catch (\Throwable $e) {
            $this->error("Неверная дата: {$this->argument('date')} (ожидаю YYYY-MM-DD)");
            return self::FAILURE;
        }

        $order = $client->orders()
            ->where('start_date', '<=', $date->toDateString())
            ->where('end_date',   '>=', $date->toDateString())
            ->whereNotIn('status', ['finished', 'completed'])
            ->orderBy('start_date')
            ->with('menuPlan')
            ->first();

        if (!$order) {
            $order = $client->orders()
                ->where('start_date', '<=', $date->toDateString())
                ->where('end_date',   '>=', $date->toDateString())
                ->orderBy('start_date')
                ->with('menuPlan')
                ->first();
        }

        $plan = $order?->effectiveMenuPlan() ?? MenuPlan::default();

        if (!$plan) {
            $this->error('У клиента нет заказа на эту дату и нет дефолтного MenuPlan.');
            return self::FAILURE;
        }

        $dailyMenu = $plan->menuFor($date);
        if (!$dailyMenu) {
            $this->error("На дату {$date->toDateString()} в плане «{$plan->name}» (id {$plan->id}) нет DailyMenu — день цикла " . $plan->globalDayFor($date) . '.');
            return self::FAILURE;
        }

        if (!$this->option('json')) {
            $this->info("Клиент #{$client->id} «{$client->name}» — {$date->toDateString()}");
            $this->line("  План: «{$plan->name}» (id {$plan->id}), день цикла: " . $plan->globalDayFor($date));
            $this->line('  Заказ: ' . ($order ? "#{$order->id} ({$order->status}, {$order->calories} ккал)" : '(нет активного — взят default plan)'));
            $this->line("  DailyMenu: #{$dailyMenu->id}");
            $this->line('');
        }

        $args = [
            'id'       => $dailyMenu->id,
            '--client' => $client->id,
        ];
        if ($this->option('json')) $args['--json'] = true;

        return Artisan::call('nutrition:menu:analyze', $args, $this->getOutput());
    }
}
