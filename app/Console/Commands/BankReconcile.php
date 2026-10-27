<?php

namespace App\Console\Commands;

use App\Services\Accounting\BankReconciler;
use Illuminate\Console\Command;

/** Нічна «Звірка»: після виписок привʼязати очевидне (див. BankReconciler::autoRun). */
class BankReconcile extends Command
{
    protected $signature = 'bank:reconcile';
    protected $description = 'Звірка банку: привʼязати вже внесене в CRM, перекази між своїми, запамʼятованих контрагентів';

    public function handle(BankReconciler $reconciler): int
    {
        $r = $reconciler->autoRun();
        $this->info("уже в CRM: {$r['link']}, між своїми: {$r['internal']}, за правилами: {$r['rule']}, лишилось: {$r['left']}");

        return self::SUCCESS;
    }
}
