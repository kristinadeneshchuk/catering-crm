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
            ->orderBy('id')
            ->get();

        if ($shifts->isEmpty()) {
            return false;
        }

        // Консолідуємо старі split-рядки (morning+evening) у один 'full'.
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
        }

        $baseRate = (float) $employee->base_rate;

        // Один виїзд — це і is_half (легасі-прапорець), і слоти «Ранок»/«Вечір»
        // з Табеля (вони пишуться з is_half=false). Раніше тут дивились ЛИШЕ на
        // is_half, тому будь-який імпорт маршрутів з ANT переоцінював ранкову
        // або вечірню зміну як 2 виїзди — оклад курʼєра подвоювався (800 → 1600),
        // і менеджеру доводилось виправляти Табель по кілька разів.
        $singleTrip = $shift->is_half
            || in_array($shift->shift_slot, [EmployeeShift::SLOT_MORNING, EmployeeShift::SLOT_EVENING], true);
        $trips    = $singleTrip ? 1 : 2;
        $basePart = $baseRate * $trips;
        $extras   = self::calcExtras($employeeId, $date, $baseRate);
        $newRate  = $basePart + $extras;
        $oldRate  = (float) $shift->rate;

        if (abs($newRate - $oldRate) < 0.01) {
            return false;
        }

        DB::transaction(function () use ($shift, $employee, $newRate, $oldRate) {
            $shift->update(['rate' => $newRate]);
            $employee->increment('balance', $newRate - $oldRate);
        });

        return true;
    }

    /**
     * Сума "надбавок" курʼєра за день:
     *  доплата за точки понад ліміт + доплата за дальню доставку
     *  (= route.recalcCost - base_rate по кожному маршруту).
     * base_rate — це ціна одного виїзду; надбавки додаються поверх кліку в Табелі.
     */
    public static function calcExtras(int $employeeId, string $date, float $baseRate): float
    {
        return (float) DeliveryRoute::where('employee_id', $employeeId)
            ->whereDate('date', $date)
            ->get()
            ->sum(fn ($r) => max(0, (float) $r->recalcCost() - $baseRate));
    }
}
