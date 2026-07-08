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
        return auth()->user()->role === 'admin';
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

    public function toggleShift(int $employeeId, string $date, string $slot = EmployeeShift::SLOT_FULL): void
    {
        if (! in_array($slot, [EmployeeShift::SLOT_FULL, EmployeeShift::SLOT_MORNING, EmployeeShift::SLOT_EVENING], true)) {
            return;
        }

        $employee = Employee::findOrFail($employeeId);
        $shift = EmployeeShift::where('employee_id', $employeeId)
            ->where('date', $date)
            ->where('shift_slot', $slot)
            ->first();

        DB::transaction(function () use ($employee, $shift, $employeeId, $date, $slot) {
            // Базова ставка за день (для кур'єра — вартість маршрутів)
            if ($employee->position === 'courier') {
                $routeQ = DeliveryRoute::where('date', $date)
                    ->where('employee_id', $employeeId);
                // Ранкові/вечірні маршрути мапляться на відповідний слот.
                if ($slot === EmployeeShift::SLOT_MORNING) {
                    $routeQ->where('shift', 'morning');
                } elseif ($slot === EmployeeShift::SLOT_EVENING) {
                    $routeQ->where('shift', 'evening');
                }
                $base = (float) $routeQ->sum('calculated_cost');
            } else {
                $base = (float) $employee->base_rate;
            }
            $bonus = $this->dutyBonus();

            // Порожня slot-заглушка (створена splitShift) поводиться як «немає зміни».
            $isEmpty = $shift && (float) $shift->rate <= 0.001 && ! $shift->is_half && ! $shift->is_duty;

            // Цикл: немає → повна → половина → немає (незалежно для кожного слоту)
            if (! $shift) {
                EmployeeShift::create([
                    'employee_id' => $employeeId,
                    'date'        => $date,
                    'shift_slot'  => $slot,
                    'rate'        => $base,
                    'is_duty'     => false,
                    'is_half'     => false,
                ]);
                $employee->increment('balance', $base);
            } elseif ($isEmpty) {
                // Активуємо порожній slot: rate=base, is_half=false. Duty не вмикаємо (окрема кнопка).
                $shift->update(['rate' => $base, 'is_half' => false]);
                $employee->increment('balance', $base);
            } elseif (! $shift->is_half) {
                $employee->decrement('balance', $shift->rate);
                $newRate = $base / 2 + ($shift->is_duty ? $bonus : 0);
                $shift->update(['is_half' => true, 'rate' => $newRate]);
                $employee->increment('balance', $newRate);
            } else {
                $employee->decrement('balance', $shift->rate);
                $shift->delete();
            }
        });
    }

    /**
     * Розділити день на дві зміни (ранок+вечір).
     * Існуюча зміна 'full' стає 'morning', вечірня створюється порожньою.
     * Якщо зміни ще не було — просто створюємо порожню morning-мітку (менеджер клацне для активації).
     *
     * Для курʼєрів: після перейменування переоцінюємо ставку morning-зміни
     * тільки по ранкових маршрутах — інакше вона несла б повний денний rate.
     * Дельту знімаємо з балансу.
     */
    public function splitShift(int $employeeId, string $date): void
    {
        $employee = Employee::findOrFail($employeeId);

        DB::transaction(function () use ($employeeId, $date, $employee) {
            $full = EmployeeShift::where('employee_id', $employeeId)
                ->where('date', $date)
                ->where('shift_slot', EmployeeShift::SLOT_FULL)
                ->first();

            if ($full) {
                $oldRate = (float) $full->rate;
                $full->update(['shift_slot' => EmployeeShift::SLOT_MORNING]);

                if ($employee->position === 'courier') {
                    $morningRoutes = (float) DeliveryRoute::where('employee_id', $employeeId)
                        ->whereDate('date', $date)
                        ->where('shift', 'morning')
                        ->sum('calculated_cost');
                    $newRate = $full->is_half ? round($morningRoutes / 2, 2) : $morningRoutes;
                    if (abs($newRate - $oldRate) > 0.001) {
                        $full->update(['rate' => $newRate]);
                        $employee->increment('balance', $newRate - $oldRate);
                    }
                }
            }

            // Створюємо порожню вечірню зміну (rate=0) — менеджер клацне щоб активувати.
            EmployeeShift::firstOrCreate([
                'employee_id' => $employeeId,
                'date'        => $date,
                'shift_slot'  => EmployeeShift::SLOT_EVENING,
            ], [
                'rate' => 0, 'is_duty' => false, 'is_half' => false,
            ]);

            // Якщо ранкової взагалі не було — теж створюємо порожню, щоб позначити split-режим.
            EmployeeShift::firstOrCreate([
                'employee_id' => $employeeId,
                'date'        => $date,
                'shift_slot'  => EmployeeShift::SLOT_MORNING,
            ], [
                'rate' => 0, 'is_duty' => false, 'is_half' => false,
            ]);
        });
    }

    /**
     * Обʼєднати ранок+вечір назад у одну зміну.
     * Вечірню зміну знімаємо (з коригуванням балансу), ранкову перейменовуємо у 'full'.
     * Якщо ранкова була порожня (rate=0) — теж видаляємо, повертаючи "порожній день".
     *
     * Для курʼєрів: після перейменування переоцінюємо ставку 'full'-зміни
     * по ВСІХ маршрутах дня (не тільки ранкових). Дельту вносимо в баланс.
     */
    public function mergeShift(int $employeeId, string $date): void
    {
        $employee = Employee::findOrFail($employeeId);

        DB::transaction(function () use ($employeeId, $date, $employee) {
            foreach ([EmployeeShift::SLOT_EVENING, EmployeeShift::SLOT_MORNING] as $slot) {
                $s = EmployeeShift::where('employee_id', $employeeId)
                    ->where('date', $date)
                    ->where('shift_slot', $slot)
                    ->first();
                if (! $s) continue;

                if ($slot === EmployeeShift::SLOT_EVENING) {
                    if ((float) $s->rate > 0) {
                        $employee->decrement('balance', (float) $s->rate);
                    }
                    $s->delete();
                } else {
                    // morning: якщо rate=0 — просто видаляємо, інакше перетворюємо на 'full'
                    if ((float) $s->rate <= 0.001) {
                        $s->delete();
                    } else {
                        $existsFull = EmployeeShift::where('employee_id', $employeeId)
                            ->where('date', $date)
                            ->where('shift_slot', EmployeeShift::SLOT_FULL)
                            ->exists();
                        if ($existsFull) {
                            $employee->decrement('balance', (float) $s->rate);
                            $s->delete();
                        } else {
                            $oldRate = (float) $s->rate;
                            $s->update(['shift_slot' => EmployeeShift::SLOT_FULL]);
                            if ($employee->position === 'courier') {
                                $allRoutes = (float) DeliveryRoute::where('employee_id', $employeeId)
                                    ->whereDate('date', $date)
                                    ->sum('calculated_cost');
                                $newRate = $s->is_half ? round($allRoutes / 2, 2) : $allRoutes;
                                if (abs($newRate - $oldRate) > 0.001) {
                                    $s->update(['rate' => $newRate]);
                                    $employee->increment('balance', $newRate - $oldRate);
                                }
                            }
                        }
                    }
                }
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

        $shiftsCount = $realShifts->count();
        $salary      = round($realShifts->sum('rate'));

        // Помісячні посади (оклад) не беруть участі в табелі змін
        $perMonthKeys = \App\Models\Position::where('payment_type', 'per_month')->pluck('key')->all();

        $absentToday = 0;
        if ($inRange) {
            $activeCount = Employee::where('is_active', true)->whereNotIn('position', $perMonthKeys)->count();
            // Присутні сьогодні = унікальні співробітники, у яких хоча б один реальний слот сьогодні.
            $presentToday = $realShifts->where('date', $today)->pluck('employee_id')->unique()->count();
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

                if ($hasShift) {
                    $isSplit = $dayShifts->contains(fn ($s) => in_array($s->shift_slot, [
                        EmployeeShift::SLOT_MORNING,
                        EmployeeShift::SLOT_EVENING,
                    ], true));

                    $slots = [];
                    foreach ($dayShifts as $s) {
                        $slots[$s->shift_slot] = [
                            'is_duty' => (bool) $s->is_duty,
                            'is_half' => (bool) $s->is_half,
                            'is_empty' => (float) $s->rate <= 0.001,
                        ];
                    }

                    // Для сумісності зі старим шаблоном — беремо флаги першої знайденої зміни.
                    $first = $dayShifts->first();
                    $days[$date] = [
                        'status'   => 'present',
                        'is_duty'  => (bool) $first->is_duty,
                        'is_half'  => (bool) $first->is_half,
                        'is_split' => $isSplit,
                        'slots'    => $slots,
                        'mileage'  => $mileageState,
                    ];
                } elseif ($date > $today) {
                    $days[$date] = ['status' => 'future', 'is_duty' => false, 'is_split' => false, 'slots' => [], 'mileage' => null];
                } elseif ($date === $today) {
                    $days[$date] = ['status' => 'absent_today', 'is_duty' => false, 'is_split' => false, 'slots' => [], 'mileage' => $mileageState];
                    $absentEmployee = true;
                } else {
                    $days[$date] = ['status' => 'off', 'is_duty' => false, 'is_split' => false, 'slots' => [], 'mileage' => $mileageState];
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
            ],
            'rows'             => $rows,
            'portions'         => $producedPortions,
            'cost_per_portion' => $costPerPortion,
            'today'            => $today,
            'duty_bonus'       => $this->dutyBonus(),
        ];
    }
}
