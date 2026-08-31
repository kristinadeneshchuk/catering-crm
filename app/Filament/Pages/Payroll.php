<?php

namespace App\Filament\Pages;

use App\Models\Account;
use App\Models\CourierMileageLog;
use App\Models\Employee;
use App\Models\EmployeeBonus;
use App\Models\EmployeePenalty;
use App\Models\EmployeeShift;
use App\Models\OrderDay;
use App\Models\Position;
use App\Models\RateHistory;
use App\Models\Setting;
use App\Models\Transaction;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\DB;

class Payroll extends Page implements HasActions, HasForms
{
    use InteractsWithActions;
    use InteractsWithForms;

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

    public function mount(): void
    {
        // Запам'ятовуємо вибір дати per-user: якщо менеджер уже обирав період —
        // при поверненні на сторінку показуємо саме його, а не «сьогодні».
        // Скидається лише коли вручну змінить дату.
        $today = Carbon::now()->format('Y-m-d');
        $this->startDate = auth()->user()->uiPref('payroll.start', $today);
        $this->endDate   = auth()->user()->uiPref('payroll.end', $today);
    }

    public function updatedStartDate($value): void
    {
        auth()->user()->setUiPref('payroll.start', $value);
    }

    public function updatedEndDate($value): void
    {
        auth()->user()->setUiPref('payroll.end', $value);
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
     * - Курʼєр: base_rate — це ціна одного виїзду. full = 2 виїзди, half = 1 виїзд.
     *   База = base_rate × кількість виїздів (700 або 1400 при ставці 700).
     *   Бонус = решта (доплати за точки понад ліміт + дальня доставка).
     * - Кухня/офіс: база = base_rate × (0.5 якщо half), бонус = решта (доплата за
     *   точки, бонус чергового тощо).
     */
    protected function splitRate(EmployeeShift $shift, Employee $emp): array
    {
        $rate = (float) $shift->rate;

        if ($emp->position === 'courier') {
            $trips = $shift->is_half ? 1 : 2;
            $base  = round((float) $emp->base_rate * $trips, 2);
            $base  = min($base, $rate);
            $bonus = max(0, round($rate - $base, 2));
            return ['base' => $base, 'bonus' => $bonus];
        }

        $half = $shift->is_half ? 0.5 : 1.0;
        $base = round((float) $emp->base_rate * $half, 2);
        $base = min($base, $rate);
        $bonus = max(0, round($rate - $base, 2));

        return ['base' => $base, 'bonus' => $bonus];
    }

    public function getData(): array
    {
        [$start, $end] = $this->dateRange();
        $dates = $this->workingDates($start, $end);

        $positions = Position::all()->keyBy('key');

        // Збираємо ID співробітників, у яких є рухи в періоді:
        //   - зміни, штрафи, пробіг (тобто реально щось накапало)
        // + усіх активних per_month (оклад капає навіть якщо не було подій).
        // Так архівовані / неактивні, що відпрацювали частину періоду, не зникають з звіту.
        $perMonthKeys = $positions->filter(fn ($p) => $p->payment_type === 'per_month')->keys()->all();

        // Не рахуємо порожні slot-заглушки (rate=0 без duty/half) — вони зʼявляються,
        // коли менеджер клацнув «розділити день» але жоден слот ще не активований.
        $movementIds = collect()
            ->merge(EmployeeShift::whereBetween('date', [$start, $end])
                ->where(function ($q) {
                    $q->where('rate', '>', 0.001)->orWhere('is_duty', true)->orWhere('is_half', true);
                })
                ->pluck('employee_id'))
            ->merge(EmployeePenalty::whereBetween('date', [$start, $end])->pluck('employee_id'))
            ->merge(EmployeeBonus::whereBetween('date', [$start, $end])->pluck('employee_id'))
            ->merge(CourierMileageLog::whereBetween('date', [$start, $end])
                ->where(function ($q) {
                    $q->whereNotNull('start_km')->orWhereNotNull('end_km')->orWhere('fuel_price_per_liter', '>', 0);
                })
                ->pluck('employee_id'))
            ->unique();

        $activeMonthlyIds = empty($perMonthKeys) ? collect() :
            Employee::where('is_active', true)
                ->whereNull('archived_at')
                ->whereIn('position', $perMonthKeys)
                ->pluck('id');

        $employeeIds = $movementIds->merge($activeMonthlyIds)->unique()->values();

        $employees = $employeeIds->isEmpty()
            ? collect()
            : Employee::whereIn('id', $employeeIds)->get()->sortBy('name')->values();

        // Зміни на період
        $shiftsByEmp = EmployeeShift::with('employee')
            ->whereBetween('date', [$start, $end])
            ->get()
            ->groupBy('employee_id');

        // Штрафи на період
        $penaltiesByEmp = EmployeePenalty::whereBetween('date', [$start, $end])
            ->get()
            ->groupBy('employee_id');

        // Премії на період (додаються до колонки «Бонус»)
        $bonusesByEmp = EmployeeBonus::whereBetween('date', [$start, $end])
            ->get()
            ->groupBy('employee_id');

        // Пробіг кур'єрів на період
        $mileageByEmp = CourierMileageLog::whereBetween('date', [$start, $end])
            ->get()
            ->groupBy('employee_id');

        // Маршрути курʼєрів на період — для розшифровки «з чого складається сума»
        // в тултіпі колонки «Зарплата» (базова + точки понад ліміт + дальні).
        $routesByEmpDate = \App\Models\DeliveryRoute::whereBetween('date', [$start, $end])
            ->get()
            ->groupBy(fn ($r) => $r->employee_id . '|' . \Carbon\Carbon::parse($r->date)->format('Y-m-d'));
        $courierBaseStops = (int) (\App\Models\Setting::where('key', 'courier_base_stops')->value('value') ?: 12);

        // Серії окладів для помісячних
        $monthlyEmps = $employees->filter(fn ($e) => optional($positions[$e->position] ?? null)->payment_type === 'per_month');
        $salaryScopes = $monthlyEmps->map(fn ($e) => 'salary:' . $e->id)->all();
        $salarySeries = RateHistory::seriesFor($salaryScopes);

        $rows = [];
        $groupTotals = [];
        $totals = ['salary' => 0, 'bonus' => 0, 'penalty' => 0, 'compensation' => 0, 'sum' => 0, 'balance' => 0];

        foreach ($employees as $emp) {
            $pos = $positions[$emp->position] ?? null;
            if (! $pos) continue;

            $group = $pos->group ?: 'other';

            if ($this->groupFilter !== 'all' && $this->groupFilter !== $group) {
                continue;
            }

            // ЗП + Бонус
            $salary = 0; $bonus = 0;

            $breakdown = [];

            if ($pos->payment_type === 'per_shift') {
                $empShifts = $shiftsByEmp->get($emp->id, collect());
                foreach ($empShifts as $shift) {
                    $split = $this->splitRate($shift, $emp);
                    $salary += $split['base'];
                    $bonus  += $split['bonus'];

                    if ($emp->position === 'courier' && ! $shift->is_planned) {
                        $breakdown[] = $this->courierDayBreakdown($shift, $emp, $routesByEmpDate, $courierBaseStops);
                    }
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

            // Премії періоду — плюсуємо у бонус (окремої колонки нема)
            $bonus += (float) ($bonusesByEmp->get($emp->id, collect())->sum('amount'));

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
                'breakdown'      => array_values(array_filter($breakdown)),
            ];
            $rows[] = $row;

            $totals['salary']       += $row['salary'];
            $totals['bonus']        += $row['bonus'];
            $totals['penalty']      += $row['penalty'];
            $totals['compensation'] += $row['compensation'];
            $totals['sum']          += $row['sum'];
            $totals['balance']      += max(0, $row['balance']);

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
        $allFop = 0;

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

            $allFop += $numer;
        }

        // Підсумок «всі» — сума ФОПів усіх груп ділиться на порції періоду
        // (як «собівартість праці на 1 продану порцію»). Знаменник той самий
        // що для менеджменту/маркетингу — порції за період.
        if (! empty($perPortion)) {
            $perPortion['all'] = [
                'fop'      => round($allFop, 0),
                'portions' => $totalPortionsInPeriod,
                'rate'     => $totalPortionsInPeriod > 0 ? round($allFop / $totalPortionsInPeriod, 2) : 0,
            ];
        }

        return $perPortion;
    }

    /**
     * Модальна виплата боргу з рядка таблиці.
     * Викликається через wire:click="mountAction('pay', { record: <employeeId> })".
     * Логіка списання боргу + запис Transaction — та сама, що і в кнопці
     * "Історія / Виплата" в EmployeeResource, щоб не було двох різних воронок.
     */
    public function payAction(): Action
    {
        return Action::make('pay')
            ->label('Виплатити')
            ->icon('heroicon-o-banknotes')
            ->color('success')
            ->size('sm')
            ->modalHeading(fn (array $arguments) => Employee::find($arguments['record'])?->name ?? 'Виплата')
            ->modalDescription('Виплата поточного боргу співробітника напряму зі сторінки Зарплати.')
            ->modalSubmitActionLabel('Виплатити')
            ->form(function (array $arguments): array {
                $employee = Employee::find($arguments['record']);
                if (! $employee || $employee->balance <= 0) {
                    return [];
                }

                return [
                    Select::make('account_id')
                        ->label('Рахунок списання')
                        ->options(fn () => Account::pluck('name', 'id'))
                        ->required()
                        ->searchable()
                        ->default(fn () => Account::where('is_default', true)->value('id') ?? Account::orderBy('id')->value('id'))
                        ->placeholder('Виберіть касу або картку'),

                    DatePicker::make('date')
                        ->label('Дата виплати')
                        ->required()
                        ->native(false)
                        ->displayFormat('d.m.Y')
                        ->default(now())
                        ->maxDate(now())
                        ->helperText('Можна поставити минулу дату — щоб закрити борг попередніх місяців.'),

                    TextInput::make('amount')
                        ->label('Сума до виплати')
                        ->numeric()
                        ->required()
                        ->default(fn () => $employee->balance)
                        ->suffix('₴')
                        ->hint('Борг: ' . number_format($employee->balance, 0) . ' ₴'),

                    TextInput::make('comment')
                        ->label('Коментар (необовʼязково)')
                        ->placeholder('напр. зарплата за червень'),
                ];
            })
            ->action(function (array $arguments, array $data): void {
                $employee = Employee::find($arguments['record']);
                if (! $employee || $employee->balance <= 0) {
                    Notification::make()->title('Немає боргу для виплати')->warning()->send();
                    return;
                }

                DB::transaction(function () use ($employee, $data) {
                    $amount  = (float) $data['amount'];
                    $account = Account::findOrFail($data['account_id']);
                    $comment = $data['comment'] ?? null;
                    $date    = ! empty($data['date']) ? Carbon::parse($data['date']) : now();

                    // Баланс списує хук Transaction::created — тут не чіпаємо,
                    // інакше подвійне списання.
                    Transaction::create([
                        'employee_id' => $employee->id,
                        'order_id'    => null,
                        'account_id'  => $account->id,
                        'amount'      => $amount,
                        'type'        => 'expense',
                        'category'    => 'Виплата ЗП',
                        'date'        => $date,
                        'comment'     => $comment ?: "Виплата ЗП: {$employee->name}",
                        'user_id'     => auth()->id(),
                    ]);
                });

                Notification::make()
                    ->title('Виплату проведено')
                    ->body('Борг зменшено, транзакцію збережено.')
                    ->success()
                    ->send();
            });
    }

    /**
     * Рядок розшифровки дня курʼєра для тултіпа «Зарплати»:
     * «27.08 — 1 000 ₴ (базова 900 + 2 точки понад 12 (+100) + дальня 150)».
     * Складові рахуємо з маршрутів НА МОМЕНТ ПЕРЕГЛЯДУ; якщо після нарахування
     * маршрути в ANT перебудували і сума розійшлась — чесно кажемо про це.
     */
    protected function courierDayBreakdown(EmployeeShift $shift, Employee $emp, $routesByEmpDate, int $baseStops): string
    {
        $date  = Carbon::parse($shift->date);
        $rate  = (float) $shift->rate;
        $base  = (float) $emp->base_rate;

        $singleTrip = $shift->is_half
            || in_array($shift->shift_slot, [EmployeeShift::SLOT_MORNING, EmployeeShift::SLOT_EVENING], true);
        $trips    = $singleTrip ? 1 : 2;
        $basePart = $base * $trips;

        $routes = $routesByEmpDate->get($emp->id . '|' . $date->format('Y-m-d'), collect());
        $extraStops = 0; $distanceFee = 0.0;
        foreach ($routes as $r) {
            $extraStops  += max(0, (int) $r->count_comps - $baseStops);
            $distanceFee += (float) $r->extraDeliveryFee();
        }
        $extraPerStop = (float) (\App\Models\Setting::where('key', 'courier_extra_per_stop')->value('value') ?: 50);
        $pointsFee = $extraStops * $extraPerStop;

        $parts = ['базова ' . number_format($basePart, 0, '.', ' ') . ($trips === 2 ? ' (2 виїзди)' : '')];
        if ($extraStops > 0) {
            $parts[] = $extraStops . ' точк. понад ' . $baseStops . ' (+' . number_format($pointsFee, 0, '.', ' ') . ')';
        }
        if ($distanceFee > 0) {
            $parts[] = 'дальня +' . number_format($distanceFee, 0, '.', ' ');
        }

        $line = $date->format('d.m') . ' — ' . number_format($rate, 0, '.', ' ') . ' ₴ (' . implode(' + ', $parts) . ')';

        if (abs(($basePart + $pointsFee + $distanceFee) - $rate) > 0.01) {
            $line .= ' ⚠ маршрути змінились після нарахування';
        }

        return $line;
    }
}
