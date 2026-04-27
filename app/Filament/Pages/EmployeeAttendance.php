<?php

namespace App\Filament\Pages;

use App\Models\DeliveryRoute;
use App\Models\Employee;
use App\Models\EmployeeShift;
use App\Models\OrderDay;
use Carbon\Carbon;
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
        $this->startDate = Carbon::now()->startOfWeek(Carbon::MONDAY)->format('Y-m-d');
        $this->endDate   = Carbon::now()->format('Y-m-d');
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

    public function toggleShift(int $employeeId, string $date): void
    {
        $employee = Employee::findOrFail($employeeId);
        $shift = EmployeeShift::where('employee_id', $employeeId)->where('date', $date)->first();

        DB::transaction(function () use ($employee, $shift, $employeeId, $date) {
            if ($shift) {
                $employee->decrement('balance', $shift->rate);
                $shift->delete();
            } else {
                if ($employee->position === 'courier') {
                    $rate = (float) DeliveryRoute::where('date', $date)
                        ->where('employee_id', $employeeId)
                        ->sum('calculated_cost');
                } else {
                    $rate = (float) $employee->base_rate;
                }
                EmployeeShift::create(['employee_id' => $employeeId, 'date' => $date, 'rate' => $rate]);
                $employee->increment('balance', $rate);
            }
        });
    }

    public function getData(): array
    {
        $dates = $this->getDates();
        if (empty($dates)) {
            return ['stats' => ['shifts' => 0, 'salary' => 0, 'absent_today' => 0], 'rows' => [], 'portions' => [], 'today' => now()->format('Y-m-d')];
        }

        $rangeStart = $dates[0];
        $rangeEnd   = end($dates);
        $today      = Carbon::now()->format('Y-m-d');
        $inRange    = $today >= $rangeStart && $today <= $rangeEnd;

        $allShifts   = EmployeeShift::whereBetween('date', [$rangeStart, $rangeEnd])->get();
        $shiftsByEmp = $allShifts->groupBy('employee_id');

        $shiftsCount = $allShifts->count();
        $salary      = round($allShifts->sum('rate'));

        $absentToday = 0;
        if ($inRange) {
            $activeCount  = Employee::where('is_active', true)->count();
            $presentToday = $allShifts->where('date', $today)->count();
            $absentToday  = max(0, $activeCount - $presentToday);
        }

        $query = Employee::where('is_active', true);
        if ($this->roleFilter === 'cook') {
            $query->whereIn('position', ['cook', 'packer', 'cleaner']);
        } elseif ($this->roleFilter === 'courier') {
            $query->where('position', 'courier');
        } elseif ($this->roleFilter === 'manager') {
            $query->whereIn('position', ['manager', 'admin']);
        }

        $posOrder  = ['cook' => 1, 'packer' => 2, 'manager' => 3, 'admin' => 4, 'courier' => 5, 'cleaner' => 6];
        $employees = $query->get()->sortBy(fn ($e) => $posOrder[$e->position] ?? 99)->values();

        $portions = OrderDay::whereBetween('date', [$rangeStart, $rangeEnd])
            ->selectRaw('date, COUNT(*) as cnt')
            ->groupBy('date')
            ->pluck('cnt', 'date');

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
            $empShifts     = $shiftsByEmp->get($emp->id, collect())->keyBy('date');
            $days          = [];
            $absentEmployee = false;

            foreach ($dates as $date) {
                if ($empShifts->has($date)) {
                    $days[$date] = 'present';
                } elseif ($date > $today) {
                    $days[$date] = 'future';
                } elseif ($date === $today) {
                    $days[$date] = 'absent_today';
                    $absentEmployee = true;
                } else {
                    $days[$date] = 'off';
                }
            }

            $rows[] = [
                'id'             => $emp->id,
                'name'           => $emp->name,
                'position'       => $emp->position,
                'position_label' => $posLabels[$emp->position] ?? $emp->position,
                'base_rate'      => $emp->base_rate,
                'is_kitchen'     => in_array($emp->position, $kitchenPositions),
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
            'rows'     => $rows,
            'portions' => $portions->toArray(),
            'today'    => $today,
        ];
    }
}
