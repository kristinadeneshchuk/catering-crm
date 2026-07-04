<?php

namespace App\Services;

use App\Models\DeliveryRoute;
use App\Models\Employee;
use App\Models\EmployeeShift;
use Illuminate\Support\Facades\DB;

/**
 * Синхронізує ставку зміни курʼєра з сумою його маршрутів у той день.
 *
 * Проблема яку розвʼязує:
 *  У курʼєра rate зміни = SUM(DeliveryRoute.calculated_cost) на цей день.
 *  Якщо менеджер поставив галочку у Табелі раніше ніж імпортувалися маршрути з ANT —
 *  зміна створюється з rate=0 і потім ніхто її не переоцінює. У «Зарплатах» курʼєр «зникає».
 *
 * Цей сервіс викликається DeliveryRouteObserver при створенні/оновленні маршруту,
 * а також бек-фільним артisan-командою для чистки існуючих «нульових» змін.
 */
class CourierShiftPricingService
{
    /**
     * Переоцінити ставку зміни курʼєра на день $date, виходячи з поточної суми маршрутів.
     * Різницю відображаємо в employee.balance (борг компанії).
     *
     * Повертає true якщо ставку змінили, false якщо зміна не знайдена або значення вже актуальне.
     */
    public function reprice(int $employeeId, string $date): bool
    {
        $employee = Employee::find($employeeId);
        if (! $employee || $employee->position !== 'courier') {
            return false;
        }

        $shift = EmployeeShift::where('employee_id', $employeeId)
            ->whereDate('date', $date)
            ->first();
        if (! $shift) {
            return false;
        }

        $routeSum = (float) DeliveryRoute::where('employee_id', $employeeId)
            ->whereDate('date', $date)
            ->sum('calculated_cost');

        // Для пів-зміни ділимо на 2 (курʼєр не буває черговим — bonus ігноруємо).
        $newRate = $shift->is_half ? round($routeSum / 2, 2) : $routeSum;
        $oldRate = (float) $shift->rate;

        if (abs($newRate - $oldRate) < 0.01) {
            return false;
        }

        DB::transaction(function () use ($shift, $employee, $newRate, $oldRate) {
            $shift->update(['rate' => $newRate]);
            $employee->increment('balance', $newRate - $oldRate);
        });

        return true;
    }
}
