<?php

namespace App\Services\Couriers;

use App\Models\CourierMileageLog;
use App\Models\CourierShiftReport;
use App\Models\DeliveryRoute;
use App\Models\Employee;
use App\Models\EmployeeBonus;
use App\Models\EmployeePenalty;
use App\Models\EmployeeShift;
use App\Models\Setting;

/**
 * Скільки курʼєр заробив за день — зібрано з чинних правил, без нових формул.
 *
 * Правила вже живуть у коді, і нарахування за ними вже лежить в employees.balance:
 *   ставка × виїзди          — EmployeeAttendance / CourierShiftPricingService::reprice
 *   точки понад ліміт, дальні — DeliveryRoute::recalcCost / calcExtras
 *   пальне й амортизація      — CourierMileageLog::compensation
 *   бонуси й штрафи           — employee_bonuses / employee_penalties
 *
 * Тут ми їх лише розкладаємо на рядки для власника. Вигадати тут власну
 * формулу — значить колись показати в Telegram одну суму, а в балансі мати
 * іншу. Тому підсумок дорівнює рівно тому, що за цей день рахує «Зарплати».
 */
class CourierPayoutCalculator
{
    /**
     * @return array{
     *     components: array<string, mixed>,
     *     total: float, cash_on_hand: float, to_pay: float,
     *     shift_slot: ?string, delivery_route_id: ?int, mileage_log_id: ?int,
     * }
     */
    public function calculate(Employee $employee, string $date): array
    {
        $baseRate  = (float) $employee->base_rate;
        $baseStops = (int) (Setting::where('key', 'courier_base_stops')->value('value') ?: 12);
        $perStop   = (float) (Setting::where('key', 'courier_extra_per_stop')->value('value') ?: 50);

        // Лише факт: запланована зміна грошей не тримає.
        $shift = EmployeeShift::where('employee_id', $employee->id)
            ->whereDate('date', $date)
            ->where('is_planned', false)
            ->orderBy('id')
            ->first();

        $bookedRate = (float) ($shift?->rate ?? 0);

        // Один виїзд — і легасі is_half, і слоти «Ранок»/«Вечір» з Табеля.
        // Те саме правило, що в CourierShiftPricingService::reprice().
        $trips = 0;
        if ($shift) {
            $single = $shift->is_half
                || in_array($shift->shift_slot, [EmployeeShift::SLOT_MORNING, EmployeeShift::SLOT_EVENING], true);
            $trips = $single ? 1 : 2;
        }

        $base = round($baseRate * $trips, 2);

        // Надбавка маршруту — рівно як у calcExtras: вартість маршруту мінус
        // ціна виїзду. Розкладаємо її на «точки» і «дальні» лише для показу.
        $routes = DeliveryRoute::with('employee')
            ->where('employee_id', $employee->id)
            ->whereDate('date', $date)
            ->orderBy('ant_route_num')
            ->get()
            ->map(function (DeliveryRoute $r) use ($baseRate, $baseStops, $perStop) {
                $stops      = (int) $r->count_comps;
                $far        = round((float) $r->extraDeliveryFee(), 2);
                $extra      = round(max(0, (float) $r->recalcCost() - $baseRate), 2);
                $extraStops = max(0, $stops - $baseStops);

                return [
                    'id'                 => $r->id,
                    'num'                => $r->ant_route_num,
                    'shift'              => $r->realShift(),
                    'stops'              => $stops,
                    'extra_stops'        => $extraStops,
                    'extra_stops_amount' => round(max(0, $extra - $far), 2),
                    'far_amount'         => min($far, $extra),
                    'extra'              => $extra,
                    'per_stop'           => $perStop,
                ];
            })
            ->values();

        $extras       = round($routes->sum('extra'), 2);
        $computedRate = round($base + $extras, 2);

        // Ставка в Табелі — те, що реально нараховано. Вона може відрізнятись від
        // розрахункової: зміни старші за 3 дні reprice не переоцінює, а менеджер
        // міг поправити ставку руками. Показуємо різницю окремим рядком, щоб
        // підсумок збігся з балансом, а не з теорією.
        $adjustment = $shift ? round($bookedRate - $computedRate, 2) : 0.0;

        $mileage = CourierMileageLog::where('employee_id', $employee->id)
            ->whereDate('date', $date)
            ->orderBy('id')
            ->get()
            ->map(fn (CourierMileageLog $m) => [
                'id'            => $m->id,
                'slot'          => $m->shift_slot,
                'start_km'      => $m->start_km,
                'end_km'        => $m->end_km,
                'km'            => (float) $m->km,
                'consumption'   => (float) $m->fuel_consumption,
                'liters'        => (float) $m->liters_used,
                'fuel_price'    => (float) $m->fuel_price_per_liter,
                'fuel_cost'     => (float) $m->fuel_cost,
                'amort_per_km'  => (float) ($m->amort_per_km ?? CourierMileageLog::currentAmortPerKm()),
                'amortization'  => (float) $m->amortization,
                'compensation'  => (float) $m->compensation,
            ])
            ->values();

        $bonuses = EmployeeBonus::where('employee_id', $employee->id)->whereDate('date', $date)->get()
            ->map(fn ($b) => ['id' => $b->id, 'amount' => (float) $b->amount, 'reason' => $b->reason])->values();

        $penalties = EmployeePenalty::where('employee_id', $employee->id)->whereDate('date', $date)->get()
            ->map(fn ($p) => ['id' => $p->id, 'amount' => (float) $p->amount, 'reason' => $p->reason])->values();

        $compensation = round($mileage->sum('compensation'), 2);
        $bonusSum     = round($bonuses->sum('amount'), 2);
        $penaltySum   = round($penalties->sum('amount'), 2);

        // Рівно те, що за цей день складає «Зарплати»: ставка + компенсація +
        // премії − штрафи.
        $total = round($bookedRate + $compensation + $bonusSum - $penaltySum, 2);

        // Готівка, яку курʼєр узяв у клієнтів і тримає при собі (підзвіт).
        // Беремо з прийнятих звітів зміни: це слова самого курʼєра за цей день.
        $cash = CourierShiftReport::where('employee_id', $employee->id)
            ->whereDate('date', $date)
            ->where('status', CourierShiftReport::STATUS_ACCEPTED)
            ->get()
            ->flatMap(fn (CourierShiftReport $r) => $r->parsed['cash'] ?? [])
            ->filter(fn ($line) => ($line['received'] ?? 0) > 0)
            ->values();

        $cashOnHand = round($cash->sum('received'), 2);

        return [
            'components' => [
                'date'          => $date,
                'employee'      => $employee->name,
                'base_rate'     => $baseRate,
                'trips'         => $trips,
                'base'          => $base,
                'routes'        => $routes->all(),
                'extras'        => $extras,
                'booked_rate'   => $bookedRate,
                'adjustment'    => $adjustment,
                'mileage'       => $mileage->all(),
                'compensation'  => $compensation,
                'bonuses'       => $bonuses->all(),
                'bonus_sum'     => $bonusSum,
                'penalties'     => $penalties->all(),
                'penalty_sum'   => $penaltySum,
                'cash'          => $cash->all(),
            ],
            'total'             => $total,
            'cash_on_hand'      => $cashOnHand,
            'to_pay'            => round($total - $cashOnHand, 2),
            'shift_slot'        => $shift?->shift_slot,
            'delivery_route_id' => $routes->first()['id'] ?? null,
            'mileage_log_id'    => $mileage->first()['id'] ?? null,
        ];
    }
}
