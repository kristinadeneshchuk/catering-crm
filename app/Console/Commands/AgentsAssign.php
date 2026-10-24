<?php

namespace App\Console\Commands;

use App\Models\AiAgent;
use App\Models\Order;
use App\Models\User;
use App\Services\Agents\TaskDesk;
use Illuminate\Console\Command;

/**
 * Поставити задачу агенту з консолі — те саме, що кнопка «Доручити агенту».
 * --shadow: тіньовий прогін (пропозиції лише для порівняння, не виконуються).
 */
class AgentsAssign extends Command
{
    protected $signature = 'agents:assign {order : id замовлення} {from : дата з} {to : дата по}
        {--agent=menu} {--template=menu_period} {--details=} {--by= : id користувача, що ставить} {--shadow}';

    protected $description = 'Доручити задачу агенту (меню на період) з консолі';

    public function handle(TaskDesk $desk): int
    {
        $order = Order::with('client')->findOrFail($this->argument('order'));
        $agent = $this->option('agent') === 'menu' ? AiAgent::serverMenu() : AiAgent::where('slug', $this->option('agent'))->firstOrFail();
        $by = User::findOrFail($this->option('by') ?: (int) explode(',', (string) config('services.finance.super_admin_ids'))[0]);

        $task = $desk->assign($agent, $by, [
            'template' => $this->option('template'), 'order_id' => $order->id,
            'period_from' => $this->argument('from'), 'period_to' => $this->argument('to'),
            'details' => $this->option('details'), 'shadow' => (bool) $this->option('shadow'),
        ]);
        $this->info("Задача #{$task->id}: {$task->title}" . ($this->option('shadow') ? ' (тіньова)' : '') . " → {$agent->name}");

        return self::SUCCESS;
    }
}
