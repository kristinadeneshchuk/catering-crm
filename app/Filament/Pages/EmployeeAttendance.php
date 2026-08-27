<?php

namespace App\Filament\Pages;

use App\Models\CourierMileageLog;
use App\Models\DeliveryRoute;
use App\Models\Employee;
use App\Models\EmployeeShift;
use App\Models\OrderDay;
use App\Models\Setting;
use Carbon\Carbon;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\DB;

class EmployeeAttendance extends Page
{
    public static function canAccess(): bool
    {
        // Менеджери ведуть табель нарівні з адміном (2026-08-27).
        return in_array(auth()->user()->role, ['admin', 'manager'], true);
    }

    protected static ?string $navigationLabel = 'Табель змін';
    protected static ?string $title = 'Табель змін';
    protected static ?string $navigationGroup = 'Система';
    protected static string $view = 'filament.pages.employee-attendance';

    public string $startDate = '';
    public string $endDate = '';
    public string $roleFilter = 'all';

    public function mount(): void
    {
        // Запам'ятовуємо вибір per-user — щоб діапазон не скидався щоразу.
        $defStart = Carbon::now()->startOfWeek(Carbon::MONDAY)->format('Y-m-d');
        $defEnd   = Carbon::now()->format('Y-m-d');
        $this->startDate = auth()->user()->uiPref('attendance.start', $defStart);
        $this->endDate   = auth()->user()->uiPref('attendance.end', $defEnd);
    }

    public function updatedStartDate($value): void
    {
        auth()->user()->setUiPref('attendance.start', $value);
    }

    public function updatedEndDate($value): void
    {
        auth()->user()->setUiPref('attendance.end', $value);
    }

    public function getDates(): array
    {
        $start = Carbon::parse($this->startDate);
        $end   = Carbon::parse($this->endDate);

        if ($end->lt($start)) {
            $end = $start->copy();
        }
        if ($start->diffInDays($end) > 60) {
            $end = $start->copy()->addDays(60);
        }

        $dates = [];
        for ($d = $start->copy(); $d->lte($end); $d->addDay()) {
            $dates[] = $d->format('Y-m-d');
        }
        return $dates;
    }

    /**
     * Сума доплати черговому кухарю — береться з налаштувань бізнесу.
     */
    protected function dutyBonus(): float
    {
        return (float) (Setting::where('key', 'duty_cook_bonus')->value('value') ?? 0);
    }

    /**
     * Клік по клітинці дня в Табелі. Цикл: немає → повна → половина → немає.
     *
     * Для курʼєрів "повна" = сума всіх маршрутів дня (ранкових + вечірніх, якщо є) —
     * тобто 1 круг = «2 виїзди», ½ = «1 виїзд». Табель не розділяє день на слоти
     * (це робиться окремо у Логістиці, для пробігу); якщо у БД є morning/evening
     * записи (застаріла split-логіка) — консолідуємо їх у 'full' перед циклом.
     */
    /**
     * Поставити курʼєру конкретний слот: ранок, вечір або обидва.
     *
     * Слот замінює собою «пів зміни»: одна зміна — це ранок АБО вечір, тобто
     * один виїзд і одинарна ставка. Дві зміни — і ранок, і вечір, два виїзди
     * і подвійна. «Половини ранку» не існує.
     *
     * На майбутню дату створюється ПЛАН: рядок є, ставка порахована для
     * перегляду, але баланс не рухається. Гроші нараховує лише підтвердження
     * виходу — інакше зарплата пішла б за невідпрацьовані дні.
     */
    public function setSlot(int $employeeId, string $date, ?string $slot): void
    {
        $employee = Employee::findOrFail($employeeId);

        DB::transaction(function () use ($employee, $employeeId, $date, $slot) {
            $shifts = EmployeeShift::where('employee_id', $employeeId)->where('date', $date)->get();

            // Знімаємо старе нарахування — план грошей не тримає, факт тримає.
            foreach ($shifts as $old) {
                if (! $old->is_planned) {
                    $employee->decrement('balance', (float) $old->rate);
                }
                $old->delete();
            }

            if ($slot === null) {
                return;
            }

            $isPlan = $date > now()->format('Y-m-d');
            $rate   = $this->slotRate($employee, $date, $slot);

            EmployeeShift::create([
                'employee_id' => $employeeId,
                'date'        => $date,
                'shift_slot'  => $slot,
                'rate'        => $rate,
                'is_duty'     => false,
                'is_half'     => false,
                'is_planned'  => $isPlan,
            ]);

            if (! $isPlan) {
                $employee->increment('balance', $rate);
            }
        });
    }

    /**
     * Ставка за слот. Для курʼєра base_rate — ціна одного виїзду.
     */
    private function slotRate(Employee $employee, string $date, string $slot): float
    {
        $base      = (float) $employee->base_rate;
        $isCourier = $employee->position === 'courier';

        $extras = $isCourier
            ? \App\Services\CourierShiftPricingService::calcExtras($employee->id, $date, $base)
            : 0.0;

        $trips = $slot === EmployeeShift::SLOT_FULL ? 2 : 1;

        return ($isCourier ? $base * $trips : $base) + $extras;
    }

    /**
     * Підтвердити всі заплановані виходи за день — план стає фактом.
     *
     * Ставку рахуємо заново: за минулий час могли зʼявитись доплати за
     * додаткові точки чи дальню доставку, і план їх ще не знав.
     *
     * Тих, хто не вийшов, менеджер знімає окремо — саме тому підтвердження
     * не робиться автоматично при настанні дня.
     */
    public function confirmDay(string $date): void
    {
        $planned = EmployeeShift::with('employee')
            ->where('date', $date)
            ->where('is_planned', true)
            ->get();

        if ($planned->isEmpty()) {
            \Filament\Notifications\Notification::make()
                ->title('Немає запланованих виходів на цей день')
                ->warning()
                ->send();

            return;
        }

        DB::transaction(function () use ($planned, $date) {
            foreach ($planned as $shift) {
                $employee = $shift->employee;

                if (! $employee) {
                    continue;
                }

                $rate = $this->slotRate($employee, $date, $shift->shift_slot ?? EmployeeShift::SLOT_FULL);

                if ($shift->is_half) {
                    $rate = $employee->position === 'courier' ? $rate : $rate / 2;
                }

                if ($shift->is_duty) {
                    $rate += $this->dutyBonus();
                }

                $shift->update(['is_planned' => false, 'rate' => $rate]);
                $employee->increment('balance', $rate);
            }
        });

        \Filament\Notifications\Notification::make()
            ->title('Виходи підтверджено: '.$planned->count())
            ->body('Тих, хто не вийшов, зніміть кліком по клітинці.')
            ->success()
            ->send();
    }

    public function toggleShift(int $employeeId, string $date): void
    {
        $employee = Employee::findOrFail($employeeId);

        // Курʼєр обирає слот у меню. Пускати його сюди не можна: консолідація
        // нижче зліпила б ранок і вечір назад у 'full' і стерла вибір.
        if ($employee->position === 'courier') {
            $has = EmployeeShift::where('employee_id', $employeeId)->where('date', $date)->exists();
            $this->setSlot($employeeId, $date, $has ? null : EmployeeShift::SLOT_FULL);

            return;
        }

        DB::transaction(function () use ($employee, $employeeId, $date) {
            // 1) Консолідація: якщо є 2+ рядки (напр. morning+evening від попередньої
            // версії) — обʼєднуємо в один 'full', сума їх rate. Баланс не рухаємо
            // (сумарний внесок у balance не змінюється).
            $shifts = EmployeeShift::where('employee_id', $employeeId)
                ->where('date', $date)
                ->orderBy('id')
                ->get();

            if ($shifts->count() >= 2) {
                $totalRate = (float) $shifts->sum('rate');
                $anyDuty   = $shifts->contains('is_duty', true);
                $anyHalf   = $shifts->contains('is_half', true);

                $keep = $shifts->first();
                $keep->update([
                    'shift_slot' => EmployeeShift::SLOT_FULL,
                    'rate'       => $totalRate,
                    'is_duty'    => $anyDuty,
                    'is_half'    => $anyHalf,
                ]);
                foreach ($shifts->skip(1) as $s) {
                    $s->delete();
                }
                $shift = $keep->fresh();
            } else {
                $shift = $shifts->first();
                if ($shift && $shift->shift_slot !== EmployeeShift::SLOT_FULL) {
                    $shift->update(['shift_slot' => EmployeeShift::SLOT_FULL]);
                }
            }

            // 2) Ставки:
            //    - Курʼєр: base_rate — це ціна ОДНОГО виїзду.
            //      full = 2 виїзди = 2 × base_rate + надбавки (доп точки + дальня доставка).
            //      half = 1 виїзд = base_rate + надбавки.
            //    - Кухня/офіс: full = base_rate; half = base_rate/2 (надбавок нема).
            $baseRate  = (float) $employee->base_rate;
            $isCourier = $employee->position === 'courier';
            $extras    = $isCourier
                ? \App\Services\CourierShiftPricingService::calcExtras($employeeId, $date, $baseRate)
                : 0.0;
            $fullRate  = ($isCourier ? $baseRate * 2 : $baseRate) + $extras;
            $halfRate  = ($isCourier ? $baseRate : $baseRate / 2) + $extras;
            $bonus     = $this->dutyBonus();

            // 3) Порожній стаб (rate=0 без duty/half) поводиться як «немає».
            $isEmpty = $shift && (float) $shift->rate <= 0.001
                && ! $shift->is_half && ! $shift->is_duty && ! $shift->is_planned;

            // 4) Стандартний цикл: немає → повна → половина → немає.
            // На майбутню дату ставимо ПЛАН: рядок є, ставка порахована для
            // перегляду, але баланс не рухається. Гроші нараховує лише
            // підтвердження виходу.
            $isPlan = $date > now()->format('Y-m-d');

            // Знімати нарахування треба тільки з факту — план грошей не тримав.
            $refund = function ($sh) use ($employee) {
                if ($sh && ! $sh->is_planned) {
                    $employee->decrement('balance', (float) $sh->rate);
                }
            };

            $credit = function (float $amount) use ($employee, $isPlan) {
                if (! $isPlan) {
                    $employee->increment('balance', $amount);
                }
            };

            if (! $shift) {
                EmployeeShift::create([
                    'employee_id' => $employeeId,
                    'date'        => $date,
                    'shift_slot'  => EmployeeShift::SLOT_FULL,
                    'rate'        => $fullRate,
                    'is_duty'     => false,
                    'is_half'     => false,
                    'is_planned'  => $isPlan,
                ]);
                $credit($fullRate);
            } elseif ($isEmpty) {
                $shift->update(['rate' => $fullRate, 'is_half' => false, 'is_planned' => $isPlan]);
                $credit($fullRate);
            } elseif (! $shift->is_half) {
                $refund($shift);
                $newRate = $halfRate + ($shift->is_duty ? $bonus : 0);
                $shift->update(['is_half' => true, 'rate' => $newRate, 'is_planned' => $isPlan]);
                $credit($newRate);
            } else {
                $refund($shift);
                $shift->delete();
            }
        });
    }

    /**
     * Призначити / зняти чергового кухаря на день.
     * Один черговий на день. Якщо зміни на день ще немає — створюємо її автоматично.
     */
    public function toggleDuty(int $employeeId, string $date): void
    {
        $employee = Employee::findOrFail($employeeId);

        if ($employee->position !== 'cook') {
            Notification::make()
                ->title('Черговим може бути лише кухар (cook)')
                ->danger()
                ->send();
            return;
        }

        $bonus = $this->dutyBonus();

        DB::transaction(function () use ($employee, $employeeId, $date, $bonus) {
            // Чергування працює тільки в режимі одної зміни на день.
            // Якщо день розділено на ранок+вечір — беремо ранкову зміну.
            $shift = EmployeeShift::where('employee_id', $employeeId)
                ->where('date', $date)
                ->whereIn('shift_slot', [EmployeeShift::SLOT_FULL, EmployeeShift::SLOT_MORNING])
                ->orderByRaw("FIELD(shift_slot, 'full', 'morning')")
                ->first();

            if ($shift && $shift->is_duty) {
                // Знімаємо чергування — мінусуємо бонус з rate і balance.
                // Сама зміна лишається.
                $shift->update([
                    'is_duty' => false,
                    'rate'    => max(0, (float) $shift->rate - $bonus),
                ]);
                $employee->decrement('balance', $bonus);
                return;
            }

            // Перевіряємо, що на цей день ще немає іншого чергового
            $existingDuty = EmployeeShift::where('date', $date)
                ->where('is_duty', true)
                ->where('employee_id', '!=', $employeeId)
                ->first();

            if ($existingDuty) {
                $other = Employee::find($existingDuty->employee_id);
                Notification::make()
                    ->title('На цей день вже призначено чергового')
                    ->body('Спочатку зніміть чергування з: ' . ($other?->name ?? '—'))
                    ->danger()
                    ->send();
                return;
            }

            if ($shift) {
                // Зміна вже є, але без чергування — додаємо бонус.
                $shift->update([
                    'is_duty' => true,
                    'rate'    => (float) $shift->rate + $bonus,
                ]);
                $employee->increment('balance', $bonus);
            } else {
                // Зміни ще немає — створюємо одразу зі статусом "черговий".
                $baseRate = (float) $employee->base_rate;
                EmployeeShift::create([
                    'employee_id' => $employeeId,
                    'date'        => $date,
                    'rate'        => $baseRate + $bonus,
                    'is_duty'     => true,
                ]);
                $employee->increment('balance', $baseRate + $bonus);
            }
        });
    }

    public function getData(): array
    {
        $dates = $this->getDates();
        if (empty($dates)) {
            return ['stats' => ['shifts' => 0, 'salary' => 0, 'absent_today' => 0], 'rows' => [], 'portions' => [], 'today' => now()->format('Y-m-d'), 'duty_bonus' => 0];
        }

        $rangeStart = $dates[0];
        $rangeEnd   = end($dates);
        $today      = Carbon::now()->format('Y-m-d');
        $inRange    = $today >= $rangeStart && $today <= $rangeEnd;

        $allShifts   = EmployeeShift::whereBetween('date', [$rangeStart, $rangeEnd])->get();
        $shiftsByEmp = $allShifts->groupBy('employee_id');

        // «Реальні» зміни (не порожні slot-заглушки, створені splitShift без активації).
        // Використовуємо для лічильників — щоб щойно розділений день (0₴+0₴) не рахувався як +2 зміни.
        $realShifts = $allShifts->filter(fn ($s) => (float) $s->rate > 0.001 || $s->is_duty || $s->is_half);

        // Гроші і лічильники рахуємо тільки з факту. План — це намір, а не
        // відпрацьована зміна: інакше зарплата за тиждень наперед з'їхала б.
        $factShifts = $realShifts->where('is_planned', false);

        // Прапорці пробігу кур'єрів: employee_id => [Y-m-d => true|false]
        $mileageFlags = [];
        $mileageRows = CourierMileageLog::whereBetween('date', [$rangeStart, $rangeEnd])
            ->get(['employee_id', 'date', 'start_km', 'end_km', 'fuel_price_per_liter']);
        foreach ($mileageRows as $m) {
            $ymd = $m->date instanceof \Carbon\Carbon ? $m->date->format('Y-m-d') : (string) $m->date;
            $filled = ($m->start_km !== null && $m->end_km !== null) || (float) $m->fuel_price_per_liter > 0;
            // Або-або: якщо на день є хоч один заповнений слот — вважаємо, що пробіг внесено.
            $mileageFlags[$m->employee_id][$ymd] = ($mileageFlags[$m->employee_id][$ymd] ?? false) || $filled;
        }

        $shiftsCount = $factShifts->count();
        $salary      = round($factShifts->sum('rate'));
        $plannedCount = $realShifts->where('is_planned', true)->count();

        // Скільки планів чекає підтвердження на кожну дату — для кнопки дня.
        $plannedByDate = $realShifts->where('is_planned', true)
            ->groupBy('date')->map->count();

        // Помісячні посади (оклад) не беруть участі в табелі змін
        $perMonthKeys = \App\Models\Position::where('payment_type', 'per_month')->pluck('key')->all();

        $absentToday = 0;
        if ($inRange) {
            $activeCount = Employee::where('is_active', true)->whereNotIn('position', $perMonthKeys)->count();
            // Присутні сьогодні = унікальні співробітники, у яких хоча б один реальний слот сьогодні.
            $presentToday = $factShifts->where('date', $today)->pluck('employee_id')->unique()->count();
            $absentToday  = max(0, $activeCount - $presentToday);
        }

        $query = Employee::where('is_active', true)->whereNotIn('position', $perMonthKeys);
        if ($this->roleFilter === 'cook') {
            $query->whereIn('position', ['cook', 'packer', 'cleaner']);
        } elseif ($this->roleFilter === 'courier') {
            $query->where('position', 'courier');
        } elseif ($this->roleFilter === 'manager') {
            $query->whereIn('position', ['manager', 'admin']);
        }

        $posOrder  = ['cook' => 1, 'packer' => 2, 'manager' => 3, 'admin' => 4, 'courier' => 5, 'cleaner' => 6];
        $employees = $query->get()->sortBy(fn ($e) => $posOrder[$e->position] ?? 99)->values();

        // Порції доставляються наступного дня після зміни (готують сьогодні на завтра).
        // Тому беремо порції з діапазону +1 день і зіставляємо працю дня X з порціями дня X+1.
        $portionsRaw = OrderDay::whereBetween('date', [$rangeStart, \Carbon\Carbon::parse($rangeEnd)->addDay()->format('Y-m-d')])
            ->selectRaw('date, COUNT(*) as cnt')
            ->groupBy('date')
            ->pluck('cnt', 'date');

        // Порції, які виробляє зміна цього дня = доставка наступного дня
        $producedPortions = [];
        foreach ($dates as $date) {
            $nextDay = \Carbon\Carbon::parse($date)->addDay()->format('Y-m-d');
            $producedPortions[$date] = (int) ($portionsRaw[$nextDay] ?? 0);
        }

        // Собівартість праці на 1 порцію: праця дня / порції, які ця зміна виробила (наступний день)
        $filteredEmployeeIds = $employees->pluck('id')->all();
        $shiftsByDate = $allShifts
            ->whereIn('employee_id', $filteredEmployeeIds)
            ->where('is_planned', false)
            ->groupBy('date');

        $costPerPortion = [];
        foreach ($dates as $date) {
            $dayCost     = (float) ($shiftsByDate->get($date, collect())->sum('rate'));
            $dayPortions = (int)   ($producedPortions[$date] ?? 0);
            if ($dayPortions > 0 && $dayCost > 0) {
                $costPerPortion[$date] = round($dayCost / $dayPortions, 2);
            }
        }

        $kitchenPositions = ['cook', 'packer', 'cleaner'];
        $posLabels = [
            'cook'    => 'Кухар',
            'manager' => 'Менеджер',
            'courier' => "Кур'єр",
            'admin'   => 'Адміністратор',
            'packer'  => 'Пакувальник',
            'cleaner' => 'Прибиральниця',
        ];

        $rows = [];
        foreach ($employees as $emp) {
            // Групуємо зміни по даті: на 1 (date) може бути 1 або 2 записи (full | morning+evening).
            $empShifts     = $shiftsByEmp->get($emp->id, collect())->groupBy('date');
            $days          = [];
            $absentEmployee = false;

            $isCourier = $emp->position === 'courier';
            foreach ($dates as $date) {
                $dayShifts  = $empShifts->get($date, collect());
                $hasShift   = $dayShifts->isNotEmpty();
                $hasMileage = ($mileageFlags[$emp->id][$date] ?? false);

                $mileageState = null;
                if ($isCourier && $date <= $today) {
                    if ($hasMileage) {
                        $mileageState = 'ok';
                    } elseif ($hasShift) {
                        $mileageState = 'missing';
                    }
                }

                // «Реальна» зміна — це не порожній стаб. Стаби ігноруємо у відображенні.
                $realDayShifts = $dayShifts->filter(fn ($s) => (float) $s->rate > 0.001 || $s->is_duty || $s->is_half);
                $hasReal = $realDayShifts->isNotEmpty();

                if ($hasReal) {
                    // Курʼєр з morning+evening від старого коду — обʼєднуємо у одне
                    // візуальне представлення: якщо ХОЧ ОДНА зі змін is_half, показуємо ½.
                    $anyDuty = $realDayShifts->contains('is_duty', true);
                    $anyHalf = $realDayShifts->contains('is_half', true);
                    $first   = $realDayShifts->first();
                    $slot    = $first->shift_slot ?: EmployeeShift::SLOT_FULL;

                    $days[$date] = [
                        'status'     => 'present',
                        'is_duty'    => $anyDuty,
                        'is_half'    => $anyHalf,
                        'is_planned' => (bool) $first->is_planned,
                        'slot'       => $slot,
                        'slot_label' => $first->slotLabel(),
                        'rate'       => round((float) $realDayShifts->sum('rate')),
                        'mileage'    => $mileageState,
                    ];
                } elseif ($date > $today) {
                    $days[$date] = ['status' => 'future', 'is_duty' => false, 'is_planned' => false, 'slot' => null, 'mileage' => null];
                } elseif ($date === $today) {
                    $days[$date] = ['status' => 'absent_today', 'is_duty' => false, 'is_planned' => false, 'slot' => null, 'mileage' => $mileageState];
                    $absentEmployee = true;
                } else {
                    $days[$date] = ['status' => 'off', 'is_duty' => false, 'is_planned' => false, 'slot' => null, 'mileage' => $mileageState];
                }
            }

            $rows[] = [
                'id'             => $emp->id,
                'name'           => $emp->name,
                'position'       => $emp->position,
                'position_label' => $posLabels[$emp->position] ?? $emp->position,
                'base_rate'      => $emp->base_rate,
                'is_kitchen'     => in_array($emp->position, $kitchenPositions),
                'is_cook'        => $emp->position === 'cook',
                'days'           => $days,
                'absent_today'   => $absentEmployee && $inRange,
            ];
        }

        return [
            'stats' => [
                'shifts'       => $shiftsCount,
                'salary'       => $salary,
                'absent_today' => $absentToday,
                'planned'      => $plannedCount,
            ],
            'rows'             => $rows,
            'planned_by_date'  => $plannedByDate,
            'portions'         => $producedPortions,
            'cost_per_portion' => $costPerPortion,
            'today'            => $today,
            'duty_bonus'       => $this->dutyBonus(),
        ];
    }
}
