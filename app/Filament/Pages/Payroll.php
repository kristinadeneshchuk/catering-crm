<?php

namespace App\Filament\Pages;

use App\Models\Account;
use App\Models\CourierMileageLog;
use App\Models\Employee;
use App\Models\EmployeePenalty;
use App\Models\EmployeeShift;
use App\Models\OrderDay;
use App\Models\Position;
use App\Models\RateHistory;
use App\Models\Setting;
use App\Models\Transaction;
use Carbon\Carbon;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\DB;

class Payroll extends Page
{
    public static function canAccess(): bool
    {
        return auth()->user()->role === 'admin';
    }

    protected static ?string $navigationLabel = 'Зарплати';
    protected static ?string $title = 'Зарплати';
    protected static ?string $navigationGroup = 'Система';
    protected static ?int    $navigationSort  = 1;
    protected static string  $view = 'filament.pages.payroll';

    public string $startDate = '';
    public string $endDate   = '';
    public string $groupFilter = 'all';

    // Модалка історії
    public ?int $selectedEmployeeId = null;

    // Виплата
    public ?float $payoutAmount   = null;
    public ?string $payoutComment = null;
    public ?int $payoutAccountId  = null;

    public function mount(): void
    {
        $this->startDate = Carbon::now()->startOfMonth()->format('Y-m-d');
        $this->endDate   = Carbon::now()->format('Y-m-d');
    }

    protected function dateRange(): array
    {
        $start = Carbon::parse($this->startDate);
        $end   = Carbon::parse($this->endDate);
        if ($end->lt($start)) $end = $start->copy();
        return [$start->format('Y-m-d'), $end->format('Y-m-d')];
    }

    protected function workingDates(string $start, string $end): array
    {
        $dates = [];
        $s = Carbon::parse($start); $e = Carbon::parse($end);
        for ($d = $s->copy(); $d->lte($e); $d->addDay()) {
            $dates[] = $d->format('Y-m-d');
        }
        return $dates;
    }

    /**
     * Розкладає rate зміни на (база, бонус).
     * Курʼєр: база = courier_base_rate (× 0.5 якщо half), бонус = решта (доплати за точки).
     * Кухар і ін. per_shift: база = employee.base_rate (× 0.5 якщо half), бонус = решта (бонус чергового).
     */
    protected function splitRate(EmployeeShift $shift, Employee $emp, float $courierBaseRate): array
    {
        $half = $shift->is_half ? 0.5 : 1.0;
        $rate = (float) $shift->rate;

        if ($emp->position === 'courier') {
            $base = round($courierBaseRate * $half, 2);
        } else {
            $base = round((float) $emp->base_rate * $half, 2);
        }

        $base  = min($base, $rate);
        $bonus = max(0, round($rate - $base, 2));

        return ['base' => $base, 'bonus' => $bonus];
    }

    public function getData(): array
    {
        [$start, $end] = $this->dateRange();
        $dates = $this->workingDates($start, $end);

        $positions = Position::all()->keyBy('key');
        $courierBaseRate = (float) (Setting::where('key', 'courier_base_rate')->value('value') ?: 700);

        // Усі співробітники (включно з помісячними)
        $employees = Employee::whereNull('archived_at')
            ->where('is_active', true)
            ->get()
            ->sortBy('name')
            ->values();

        // Зміни на період
        $shiftsByEmp = EmployeeShift::with('employee')
            ->whereBetween('date', [$start, $end])
            ->get()
            ->groupBy('employee_id');

        // Штрафи на період
        $penaltiesByEmp = EmployeePenalty::whereBetween('date', [$start, $end])
            ->get()
            ->groupBy('employee_id');

        // Пробіг кур'єрів на період
        $mileageByEmp = CourierMileageLog::whereBetween('date', [$start, $end])
            ->get()
            ->groupBy('employee_id');

        // Серії окладів для помісячних
        $monthlyEmps = $employees->filter(fn ($e) => optional($positions[$e->position] ?? null)->payment_type === 'per_month');
        $salaryScopes = $monthlyEmps->map(fn ($e) => 'salary:' . $e->id)->all();
        $salarySeries = RateHistory::seriesFor($salaryScopes);

        $rows = [];
        $groupTotals = [];
        $totals = ['salary' => 0, 'bonus' => 0, 'penalty' => 0, 'compensation' => 0, 'sum' => 0];

        foreach ($employees as $emp) {
            $pos = $positions[$emp->position] ?? null;
            if (! $pos) continue;

            $group = $pos->group ?: 'other';

            if ($this->groupFilter !== 'all' && $this->groupFilter !== $group) {
                continue;
            }

            // ЗП + Бонус
            $salary = 0; $bonus = 0;

            if ($pos->payment_type === 'per_shift') {
                $empShifts = $shiftsByEmp->get($emp->id, collect());
                foreach ($empShifts as $shift) {
                    $split = $this->splitRate($shift, $emp, $courierBaseRate);
                    $salary += $split['base'];
                    $bonus  += $split['bonus'];
                }
            } else {
                // per_month — оклад / monthly_working_days × кількість робочих днів періоду
                // (як у AnalyticsController: робочі = всі дні крім неділь)
                $workDays = max(1, (int) ($pos->monthly_working_days ?: 22));
                $series = $salarySeries['salary:' . $emp->id] ?? null;
                $workingDates = array_values(array_filter($dates, fn ($ymd) => ! Carbon::parse($ymd)->isSunday()));
                foreach ($workingDates as $ymd) {
                    $salaryOnDay = empty($series) ? (float) $emp->base_rate : RateHistory::valueOn($series, $ymd);
                    $salary += $salaryOnDay / $workDays;
                }
            }

            // Штраф
            $penaltyAmount = (float) ($penaltiesByEmp->get($emp->id, collect())->sum('amount'));

            // Компенсація (тільки кур'єри)
            $compensation = 0;
            if ($emp->position === 'courier') {
                $logs = $mileageByEmp->get($emp->id, collect());
                foreach ($logs as $log) {
                    $compensation += $log->compensation;
                }
            }

            $sum = $salary + $bonus - $penaltyAmount + $compensation;

            $row = [
                'id'             => $emp->id,
                'name'           => $emp->name,
                'position'       => $pos->name,
                'position_key'   => $emp->position,
                'group'          => $group,
                'group_label'    => Position::GROUPS[$group] ?? $group,
                'payment_type'   => $pos->payment_type,
                'salary'         => round($salary, 2),
                'bonus'          => round($bonus, 2),
                'penalty'        => round($penaltyAmount, 2),
                'compensation'   => round($compensation, 2),
                'sum'            => round($sum, 2),
                'balance'        => round((float) $emp->balance, 2),
            ];
            $rows[] = $row;

            $totals['salary']       += $row['salary'];
            $totals['bonus']        += $row['bonus'];
            $totals['penalty']      += $row['penalty'];
            $totals['compensation'] += $row['compensation'];
            $totals['sum']          += $row['sum'];

            $groupTotals[$group] = $groupTotals[$group] ?? ['salary' => 0, 'bonus' => 0, 'penalty' => 0, 'compensation' => 0, 'sum' => 0];
            $groupTotals[$group]['salary']       += $row['salary'];
            $groupTotals[$group]['bonus']        += $row['bonus'];
            $groupTotals[$group]['penalty']      += $row['penalty'];
            $groupTotals[$group]['compensation'] += $row['compensation'];
            $groupTotals[$group]['sum']          += $row['sum'];
        }

        // Сортуємо рядки за групою (kitchen → couriers → management → marketing → other)
        $groupOrder = ['kitchen' => 1, 'couriers' => 2, 'management' => 3, 'marketing' => 4, 'other' => 5];
        usort($rows, function ($a, $b) use ($groupOrder) {
            $ga = $groupOrder[$a['group']] ?? 9;
            $gb = $groupOrder[$b['group']] ?? 9;
            if ($ga !== $gb) return $ga <=> $gb;
            return strcmp($a['name'], $b['name']);
        });

        // Грн / порція по групах
        $perPortion = $this->calcPerPortion($start, $end, $groupTotals);

        return [
            'rows'        => $rows,
            'totals'      => array_map(fn ($v) => round($v, 2), $totals),
            'group_totals'=> array_map(fn ($g) => array_map(fn ($v) => round($v, 2), $g), $groupTotals),
            'per_portion' => $perPortion,
            'groups'      => Position::GROUPS,
        ];
    }

    /**
     * Грн / порція по групах.
     *  - Кухня: ФОП дня X / порції дня X+1 (виробництво готує сьогодні на завтра).
     *  - Курʼєри (ЗП + бонус + компенсація): ФОП дня X / порції дня X (везуть сьогоднішні).
     *  - Менеджмент / маркетинг: ФОП періоду / порції періоду (оклад не на день).
     */
    protected function calcPerPortion(string $start, string $end, array $groupTotals): array
    {
        // Порції періоду — підраховуємо за датою доставки.
        $portionsByDate = OrderDay::whereBetween('date', [
            Carbon::parse($start)->format('Y-m-d'),
            Carbon::parse($end)->addDay()->format('Y-m-d'), // +1 щоб мати "завтра" для кухні
        ])
            ->selectRaw('date, COUNT(*) as cnt')
            ->groupBy('date')
            ->pluck('cnt', 'date');

        $totalPortionsInPeriod  = 0;
        $totalKitchenProduced   = 0; // = доставка дня X+1 для кожного дня X у періоді
        $totalCourierDelivered  = 0; // = доставка дня X для кожного дня X у періоді

        $s = Carbon::parse($start); $e = Carbon::parse($end);
        for ($d = $s->copy(); $d->lte($e); $d->addDay()) {
            $ymd = $d->format('Y-m-d');
            $next = $d->copy()->addDay()->format('Y-m-d');
            $totalPortionsInPeriod  += (int) ($portionsByDate[$ymd]  ?? 0);
            $totalKitchenProduced   += (int) ($portionsByDate[$next] ?? 0);
            $totalCourierDelivered  += (int) ($portionsByDate[$ymd]  ?? 0);
        }

        $perPortion = [];

        foreach (['kitchen', 'couriers', 'management', 'marketing', 'other'] as $group) {
            if (! isset($groupTotals[$group])) continue;
            $g = $groupTotals[$group];

            if ($group === 'kitchen') {
                $denom = $totalKitchenProduced;
                $numer = $g['salary'] + $g['bonus'] - $g['penalty'];
            } elseif ($group === 'couriers') {
                $denom = $totalCourierDelivered;
                $numer = $g['salary'] + $g['bonus'] + $g['compensation'] - $g['penalty'];
            } else {
                $denom = $totalPortionsInPeriod;
                $numer = $g['salary'] + $g['bonus'] - $g['penalty'];
            }

            $perPortion[$group] = [
                'fop'      => round($numer, 0),
                'portions' => $denom,
                'rate'     => $denom > 0 ? round($numer / $denom, 2) : 0,
            ];
        }

        return $perPortion;
    }

    /**
     * Історія начислень/списань для співробітника за весь час (для модалки).
     */
    public function getEmployeeHistory(int $employeeId): array
    {
        $emp = Employee::findOrFail($employeeId);

        $events = [];

        // Зміни
        $shifts = EmployeeShift::where('employee_id', $employeeId)
            ->orderBy('date', 'desc')
            ->limit(200)
            ->get();
        foreach ($shifts as $s) {
            $label = 'Зміна';
            if ($s->is_duty)  $label .= ' ⭐ черговий';
            if ($s->is_half)  $label .= ' (½)';
            $events[] = [
                'date'   => $s->date,
                'amount' => (float) $s->rate,
                'kind'   => 'shift',
                'label'  => $label,
            ];
        }

        // Штрафи
        $pens = EmployeePenalty::where('employee_id', $employeeId)
            ->orderBy('date', 'desc')
            ->get();
        foreach ($pens as $p) {
            $events[] = [
                'date'   => $p->date,
                'amount' => -(float) $p->amount,
                'kind'   => 'penalty',
                'label'  => 'Штраф' . ($p->reason ? ': ' . $p->reason : ''),
            ];
        }

        // Компенсація
        if ($emp->position === 'courier') {
            $logs = CourierMileageLog::where('employee_id', $employeeId)
                ->orderBy('date', 'desc')
                ->get();
            foreach ($logs as $l) {
                if ($l->compensation > 0) {
                    $events[] = [
                        'date'   => $l->date,
                        'amount' => (float) $l->compensation,
                        'kind'   => 'comp',
                        'label'  => "Компенсація ({$l->km} км × {$l->amortization}₴ + пальне " . (int) $l->fuel_uah . '₴)',
                    ];
                }
            }
        }

        // Виплати (Transaction)
        $txs = Transaction::where('employee_id', $employeeId)
            ->orderBy('date', 'desc')
            ->get();
        foreach ($txs as $t) {
            $events[] = [
                'date'   => $t->date,
                'amount' => -(float) abs($t->amount),
                'kind'   => 'payout',
                'label'  => 'Виплата' . ($t->comment ? ': ' . $t->comment : ''),
            ];
        }

        // Сортуємо за датою (новіше → старіше)
        usort($events, function ($a, $b) {
            $da = $a['date'] instanceof \Carbon\Carbon ? $a['date']->format('Y-m-d') : (string) $a['date'];
            $db = $b['date'] instanceof \Carbon\Carbon ? $b['date']->format('Y-m-d') : (string) $b['date'];
            return strcmp($db, $da);
        });

        return [
            'employee' => [
                'id'       => $emp->id,
                'name'     => $emp->name,
                'position' => $emp->position,
                'balance'  => (float) $emp->balance,
            ],
            'events' => $events,
        ];
    }

    public function openHistory(int $employeeId): void
    {
        $this->selectedEmployeeId = $employeeId;
        $emp = Employee::find($employeeId);
        $this->payoutAmount   = $emp ? (float) $emp->balance : null;
        $this->payoutComment  = null;
        // Дефолтний рахунок — позначений is_default, або перший
        $this->payoutAccountId = Account::where('is_default', true)->value('id')
            ?? Account::orderBy('id')->value('id');
    }

    public function closeHistory(): void
    {
        $this->selectedEmployeeId = null;
        $this->payoutAmount   = null;
        $this->payoutComment  = null;
        $this->payoutAccountId = null;
    }

    public function getAccountOptions(): array
    {
        return Account::orderBy('name')->pluck('name', 'id')->all();
    }

    public function payout(): void
    {
        if (! $this->selectedEmployeeId || ! $this->payoutAmount || $this->payoutAmount <= 0) {
            Notification::make()->title('Вкажіть суму > 0')->danger()->send();
            return;
        }
        if (! $this->payoutAccountId) {
            Notification::make()->title('Виберіть рахунок списання')->danger()->send();
            return;
        }

        $emp     = Employee::findOrFail($this->selectedEmployeeId);
        $account = Account::findOrFail($this->payoutAccountId);
        $amount  = round((float) $this->payoutAmount, 2);
        $comment = $this->payoutComment ?: ('Виплата ЗП: ' . $emp->name);

        DB::transaction(function () use ($emp, $account, $amount, $comment) {
            // 1. Зменшуємо борг компанії перед співробітником
            $emp->decrement('balance', $amount);

            // 2. Транзакцію бачить PaymentObserver і автоматично віднімає з рахунку
            Transaction::create([
                'employee_id' => $emp->id,
                'order_id'    => null,
                'account_id'  => $account->id,
                'amount'      => $amount,
                'type'        => 'expense',
                'category'    => 'Виплата ЗП',
                'date'        => now(),
                'comment'     => $comment,
                'user_id'     => auth()->id(),
            ]);
        });

        Notification::make()
            ->title('Виплачено ' . number_format($amount, 2, '.', ' ') . ' ₴ — ' . $emp->name)
            ->success()
            ->send();

        $this->closeHistory();
    }
}
