<?php

namespace App\Console\Commands;

use App\Models\Employee;
use App\Models\Transaction;
use Illuminate\Console\Command;

/**
 * Звіряє збережене поле Employee.balance («Борг компанії») з реальним леджером
 * і за потреби виправляє його.
 *
 * Проблема яку розв'язує:
 *  balance пополнюється/списується вручну з десятка місць (Табель, штрафи, премії,
 *  репрайс кур'єрів, ручні правки). Будь-який пропущений increment/decrement лишає
 *  дрейф: поле не збігається з фактом. «Зарплати» і історія рахуються із сирих
 *  записів заново — тож джерело правди саме вони, а не денормалізоване поле.
 *
 * Леджер = Σ(зміни.rate) + Σ(премії) − Σ(штрафи) + Σ(компенсація, лише кур'єри) − Σ(виплати).
 * Формула повторює Employee::buildHistory().
 */
class ReconcileEmployeeBalances extends Command
{
    protected $signature = 'employees:reconcile-balance {--apply : Записати виправлення (без прапорця — лише показ)}';
    protected $description = 'Звірити Employee.balance («Борг компанії») з реальним леджером і виправити дрейф';

    public function handle(): int
    {
        $apply = $this->option('apply');
        $rows = [];

        foreach (Employee::orderBy('name')->get() as $emp) {
            $sum = 0.0;
            foreach ($emp->shifts as $s)    $sum += (float) $s->rate;
            foreach ($emp->bonuses as $b)   $sum += (float) $b->amount;
            foreach ($emp->penalties as $p) $sum -= (float) $p->amount;
            if ($emp->position === 'courier') {
                foreach ($emp->mileageLogs as $l) $sum += (float) $l->compensation;
            }
            foreach (Transaction::where('employee_id', $emp->id)->get() as $t) {
                $sum -= (float) abs($t->amount);
            }

            $ledger = round($sum, 2);
            $stored = round((float) $emp->balance, 2);
            $diff   = round($stored - $ledger, 2);

            if (abs($diff) < 0.01) {
                continue;
            }

            $rows[] = [$emp->name, number_format($stored, 2), number_format($ledger, 2), number_format($diff, 2)];

            if ($apply) {
                $emp->update(['balance' => $ledger]);
            }
        }

        if (empty($rows)) {
            $this->info('Усі баланси збігаються з леджером. Дрейфу немає.');
            return self::SUCCESS;
        }

        $this->table(['Співробітник', 'Було (stored)', 'Стало (ledger)', 'Різниця'], $rows);

        if ($apply) {
            $this->info('Виправлено записів: ' . count($rows) . '.');
        } else {
            $this->warn('Це сухий прогін. Щоб застосувати — запусти з --apply.');
        }

        return self::SUCCESS;
    }
}
