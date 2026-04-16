<?php

namespace App\Filament\Pages;

use App\Models\DeliveryRoute;
use App\Models\Employee;
use App\Models\EmployeeShift;
use Filament\Pages\Page;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\DB;

class EmployeeAttendance extends Page
{
    public static function canAccess(): bool
    {
        return auth()->user()->role === 'admin';
    }

    protected static ?string $navigationLabel = 'Табель змін';
    protected static ?string $title = 'Щоденний табель';
    protected static ?string $navigationGroup = 'Система';

    protected static string $view = 'filament.pages.employee-attendance';

    public $date;
    public $attendance = [];

    public function mount()
    {
        // При відкритті ставимо сьогоднішню дату
        $this->date = now()->format('Y-m-d');
        $this->loadAttendance();
    }

    public $dailyTotal = 0; // Додай цю властивість на початку

    public function loadAttendance()
    {
        $employees = Employee::where('is_active', true)->get();

        // Витрати з маршрутів по кур'єрах за цей день
        $courierEarnings = DeliveryRoute::where('date', $this->date)
            ->whereNotNull('employee_id')
            ->selectRaw('employee_id, SUM(calculated_cost) as total')
            ->groupBy('employee_id')
            ->pluck('total', 'employee_id');

        // Отримуємо всі збережені зміни за цей день
        $existingShifts = EmployeeShift::where('date', $this->date)->get();

        // Рахуємо суму для "зеленої панелі"
        $this->dailyTotal = $existingShifts->sum('rate');

        $shiftsMap = $existingShifts->keyBy('employee_id');
        $this->attendance = [];

        foreach ($employees as $emp) {
            $shift = $shiftsMap->get($emp->id);

            if ($emp->position === 'courier') {
                $earned = (float) ($courierEarnings[$emp->id] ?? 0);
                $rate   = $shift ? $shift->rate : $earned;
            } else {
                $rate = $shift ? $shift->rate : $emp->base_rate;
            }

            $this->attendance[$emp->id] = [
                'present'          => (bool) $shift,
                'rate'             => $rate,
                'name'             => $emp->name,
                'position'         => $emp->position,
                'courier_earned'   => $emp->position === 'courier' ? (float) ($courierEarnings[$emp->id] ?? 0) : null,
            ];
        }
    }

    // Метод для збереження (викликається з кнопки)
    public function save()
    {
        DB::transaction(function () {
            foreach ($this->attendance as $empId => $data) {
                $employee = Employee::find($empId);
                $existingShift = EmployeeShift::where('employee_id', $empId)->where('date', $this->date)->first();

                if ($data['present']) {
                    // Якщо менеджер поставив галочку "Був"
                    if (!$existingShift) {
                        // Створюємо запис про зміну
                        EmployeeShift::create([
                            'employee_id' => $empId,
                            'date' => $this->date,
                            'rate' => $data['rate'],
                        ]);
                        // Плюсуємо борг в основну картку співробітника
                        $employee->increment('balance', $data['rate']);
                    } else {
                        // Якщо зміна була, але ми змінили суму (rate) вручну
                        if ((float)$existingShift->rate !== (float)$data['rate']) {
                            $diff = $data['rate'] - $existingShift->rate;
                            $existingShift->update(['rate' => $data['rate']]);
                            $employee->increment('balance', $diff);
                        }
                    }
                } else {
                    // Якщо галочку зняли
                    if ($existingShift) {
                        // Віднімаємо борг назад і видаляємо зміну
                        $employee->decrement('balance', $existingShift->rate);
                        $existingShift->delete();
                    }
                }
            }
        });

        Notification::make()->title('Табель успішно збережено')->success()->send();
        $this->loadAttendance(); // Перезавантажуємо дані
    }
}