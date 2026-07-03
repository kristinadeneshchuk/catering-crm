<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Client;
use App\Models\CourierMileageLog;
use App\Models\Employee;
use App\Models\EmployeeBonus;
use App\Models\EmployeePenalty;
use App\Models\EmployeeShift;
use App\Models\OrderDay;
use App\Models\Position;
use App\Models\RateHistory;
use App\Models\Transaction;
use Carbon\Carbon;

/**
 * Зведення каси за один день.
 * Використовується у шапці «Журналу транзакцій» — щоб менеджер міг звірити день:
 * скільки прийшло, скільки пішло, скільки ЗП, які залишки на рахунках,
 * і хто ще не сплатив за сьогодні.
 */
class DailyCashService
{
    public function summarize(string $date): array
    {
        $ymd = Carbon::parse($date)->format('Y-m-d');

        return [
            'date'             => $ymd,
            'accounts'         => $this->accounts(),
            'income'           => $this->income($ymd),
            'incomeByAccount'  => $this->incomeByAccount($ymd),
            'expenses'         => $this->generalExpenses($ymd),
            'salaries'         => $this->salaryPayouts($ymd),
            'purchases'        => $this->purchases($ymd),
            'fop'              => $this->fopAccrued($ymd),
            'unpaid'           => $this->unpaidClients($ymd),
        ];
    }

    protected function accounts(): array
    {
        $rows = Account::orderBy('is_default', 'desc')->orderBy('name')->get()
            ->map(fn ($a) => [
                'id'      => $a->id,
                'name'    => $a->name,
                'type'    => $a->type,
                'balance' => (float) $a->balance,
            ])->all();

        return [
            'rows'  => $rows,
            'total' => array_sum(array_column($rows, 'balance')),
        ];
    }

    /**
     * Прихід дня — реальні грошові надходження.
     * Виключаємо бухгалтерські записи «Нове замовлення / Зміна замовлення»
     * (у них account_id = null) — інакше цифри злітають до небес.
     * Також виключаємо ЗП/склад — вони йдуть в свої плитки.
     */
    protected function income(string $ymd): array
    {
        $q = Transaction::whereDate('date', $ymd)
            ->where('type', 'income')
            ->whereNotNull('account_id')
            ->whereNull('employee_id')
            ->whereNull('stock_document_id');
        return [
            'sum'   => (float) $q->sum('amount'),
            'count' => (int) $q->count(),
        ];
    }

    /**
     * Прихід дня в розрізі рахунків — щоб менеджер міг звіряти
     * «скільки готівки, скільки на картку, скільки на моно» окремо.
     * Правила відбору — ті самі що в income().
     */
    protected function incomeByAccount(string $ymd): array
    {
        $rows = Transaction::whereDate('date', $ymd)
            ->where('type', 'income')
            ->whereNotNull('account_id')
            ->whereNull('employee_id')
            ->whereNull('stock_document_id')
            ->selectRaw('account_id, SUM(amount) as total, COUNT(*) as cnt')
            ->groupBy('account_id')
            ->with('account:id,name')
            ->orderByDesc('total')
            ->get()
            ->map(fn ($r) => [
                'account_id' => $r->account_id,
                'name'       => $r->account?->name ?? '—',
                'total'      => (float) $r->total,
                'count'      => (int) $r->cnt,
            ])
            ->values()
            ->all();

        return $rows;
    }

    /**
     * Витрати дня — усі грошові рухи «мінус», крім ЗП і закупівель.
     * Включаємо type=refund (повернення клієнтам) — це теж вихід готівки.
     */
    protected function generalExpenses(string $ymd): array
    {
        $q = Transaction::whereDate('date', $ymd)
            ->whereNotNull('account_id')
            ->whereNull('employee_id')
            ->whereNull('stock_document_id')
            ->where(function ($q) {
                $q->where('type', 'expense')->orWhere('type', 'refund');
            });
        return [
            'sum'   => (float) $q->sum('amount'),
            'count' => (int) $q->count(),
        ];
    }

    /** Виплати ЗП — усі транзакції з employee_id (за визначенням мають account_id). */
    protected function salaryPayouts(string $ymd): array
    {
        $q = Transaction::whereDate('date', $ymd)
            ->where('type', 'expense')
            ->whereNotNull('employee_id');
        return [
            'sum'   => (float) $q->sum('amount'),
            'count' => (int) $q->count(),
        ];
    }

    /** Закупівлі — витрати за складськими документами. */
    protected function purchases(string $ymd): array
    {
        $q = Transaction::whereDate('date', $ymd)
            ->where('type', 'expense')
            ->whereNotNull('stock_document_id');
        return [
            'sum'   => (float) $q->sum('amount'),
            'count' => (int) $q->count(),
        ];
    }

    /**
     * ФОП нараховано за день (розбивка kitchen / couriers / other).
     * Дублює логіку [AnalyticsController.php:196] + Payroll,
     * але тільки для одного дня.
     */
    protected function fopAccrued(string $ymd): array
    {
        $positions = Position::all()->keyBy('key');
        $groupOf   = fn (?string $key) => $key ? ($positions[$key]->group ?? 'other') : 'other';

        $buckets = ['kitchen' => 0.0, 'couriers' => 0.0, 'other' => 0.0];

        // Зміни цього дня
        foreach (EmployeeShift::whereDate('date', $ymd)->with('employee')->get() as $shift) {
            $group = $groupOf($shift->employee?->position);
            $key = in_array($group, ['kitchen', 'couriers'], true) ? $group : 'other';
            $buckets[$key] += (float) $shift->rate;
        }

        // Премії цього дня
        foreach (EmployeeBonus::whereDate('date', $ymd)->with('employee')->get() as $bonus) {
            $group = $groupOf($bonus->employee?->position);
            $key = in_array($group, ['kitchen', 'couriers'], true) ? $group : 'other';
            $buckets[$key] += (float) $bonus->amount;
        }

        // Штрафи цього дня — зменшують ФОП
        foreach (EmployeePenalty::whereDate('date', $ymd)->with('employee')->get() as $pen) {
            $group = $groupOf($pen->employee?->position);
            $key = in_array($group, ['kitchen', 'couriers'], true) ? $group : 'other';
            $buckets[$key] -= (float) $pen->amount;
        }

        // Компенсація кур'єрам за пробіг
        foreach (CourierMileageLog::whereDate('date', $ymd)->get() as $log) {
            $buckets['couriers'] += (float) $log->compensation;
        }

        // Помісячні (оклад) — тільки якщо це не неділя
        if (! Carbon::parse($ymd)->isSunday()) {
            $perMonthKeys = $positions->filter(fn ($p) => $p->payment_type === 'per_month')->keys()->all();
            if (! empty($perMonthKeys)) {
                $emps = Employee::where('is_active', true)
                    ->whereNull('archived_at')
                    ->whereIn('position', $perMonthKeys)
                    ->get();
                $series = RateHistory::seriesFor($emps->map(fn ($e) => 'salary:' . $e->id)->all());
                foreach ($emps as $emp) {
                    $pos = $positions[$emp->position] ?? null;
                    if (! $pos) continue;
                    $workDays = max(1, (int) ($pos->monthly_working_days ?: 22));
                    $salaryOnDay = RateHistory::valueOn($series['salary:' . $emp->id] ?? null, $ymd);
                    if ($salaryOnDay <= 0) $salaryOnDay = (float) $emp->base_rate;
                    $share = $salaryOnDay / $workDays;
                    $group = $pos->group ?? 'other';
                    $key = in_array($group, ['kitchen', 'couriers'], true) ? $group : 'other';
                    $buckets[$key] += $share;
                }
            }
        }

        $buckets = array_map(fn ($v) => round($v), $buckets);
        return [
            'kitchen'  => $buckets['kitchen'],
            'couriers' => $buckets['couriers'],
            'other'    => $buckets['other'],
            'total'    => $buckets['kitchen'] + $buckets['couriers'] + $buckets['other'],
        ];
    }

    /**
     * Клієнти, у яких на дату $ymd є OrderDay (доставка сьогодні)
     * і Client.balance < 0 (недоплатили). Береться саме баланс клієнта,
     * бо він враховує часткові оплати й попередні борги.
     */
    protected function unpaidClients(string $ymd): array
    {
        $clientIds = OrderDay::whereDate('date', $ymd)
            ->whereHas('order', fn ($q) => $q->whereNotIn('status', ['cancelled']))
            ->with('order:id,client_id')
            ->get()
            ->pluck('order.client_id')
            ->filter()
            ->unique()
            ->values();

        if ($clientIds->isEmpty()) {
            return ['count' => 0, 'sum' => 0.0, 'rows' => []];
        }

        $rows = Client::whereIn('id', $clientIds)
            ->where('balance', '<', 0)
            ->orderBy('balance')
            ->get(['id', 'name', 'phone', 'balance'])
            ->map(fn ($c) => [
                'id'      => $c->id,
                'name'    => $c->name,
                'phone'   => $c->phone,
                'debt'    => round(-(float) $c->balance),
            ])
            ->values()
            ->all();

        return [
            'count' => count($rows),
            'sum'   => round(array_sum(array_column($rows, 'debt'))),
            'rows'  => $rows,
        ];
    }
}
