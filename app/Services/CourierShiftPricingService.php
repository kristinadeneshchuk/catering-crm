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
    /**
     * $routeShift — 'morning'|'evening'|null. Якщо задано — переоцінюємо тільки
     * зміну відповідного слоту (morning route → morning shift), інакше — усі слоти на цю дату.
     */
    public function reprice(int $employeeId, string $date, ?string $routeShift = null): bool
    {
        $employee = Employee::find($employeeId);
        if (! $employee || $employee->position !== 'courier') {
            return false;
        }

        $shifts = EmployeeShift::where('employee_id', $employeeId)
            ->whereDate('date', $date)
            ->get();

        if ($shifts->isEmpty()) {
            return false;
        }

        // Мапа slot → route.shift для запиту суми маршрутів.
        $slotToRouteShift = [
            EmployeeShift::SLOT_MORNING => 'morning',
            EmployeeShift::SLOT_EVENING => 'evening',
            EmployeeShift::SLOT_FULL    => null, // full = усі маршрути дня
        ];

        $anyChanged = false;

        foreach ($shifts as $shift) {
            // Якщо переоцінка викликана конкретним маршрутом — оновлюємо тільки відповідний слот.
            if ($routeShift !== null) {
                $expectedSlot = $routeShift === 'morning'
                    ? EmployeeShift::SLOT_MORNING
                    : EmployeeShift::SLOT_EVENING;
                // Якщо у працівника один "full" слот — все ще оновлюємо його (гібридний випадок).
                if ($shift->shift_slot !== EmployeeShift::SLOT_FULL && $shift->shift_slot !== $expectedSlot) {
                    continue;
                }
            }

            $routeQuery = DeliveryRoute::where('employee_id', $employeeId)
                ->whereDate('date', $date);
            $mapped = $slotToRouteShift[$shift->shift_slot] ?? null;
            if ($mapped !== null) {
                $routeQuery->where('shift', $mapped);
            }
            $routeSum = (float) $routeQuery->sum('calculated_cost');

            $newRate = $shift->is_half ? round($routeSum / 2, 2) : $routeSum;
            $oldRate = (float) $shift->rate;

            if (abs($newRate - $oldRate) < 0.01) {
                continue;
            }

            DB::transaction(function () use ($shift, $employee, $newRate, $oldRate) {
                $shift->update(['rate' => $newRate]);
                $employee->increment('balance', $newRate - $oldRate);
            });
            $anyChanged = true;
        }

        return $anyChanged;
    }
}
