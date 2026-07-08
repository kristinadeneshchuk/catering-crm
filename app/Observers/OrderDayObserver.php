<?php

namespace App\Observers;

use App\Models\DeliveryRoute;
use App\Models\Employee;
use App\Models\EmployeeShift;
use App\Models\OrderDay;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class OrderDayObserver
{
    /**
     * При додаванні дня — перерахунок end_date і статусу замовлення
     * (щоб finished автоматично оживало в active/new, якщо знову є майбутні дні).
     */
    public function created(OrderDay $orderDay): void
    {
        $this->syncOrder($orderDay);
        // Якщо при створенні вже виставлено extra_delivery_fee і прив'язано маршрут — додаємо в нього.
        if ((float) $orderDay->extra_delivery_fee > 0 && $orderDay->ant_route_num) {
            $this->applyExtraDelta(
                $orderDay->ant_route_num,
                $orderDay->resolveDeliveryDate(),
                (float) $orderDay->extra_delivery_fee,
            );
        }
    }

    /**
     * Якщо у дня змінилась дата — підлаштувати end_date парента.
     * Якщо змінилась extra_delivery_fee / ant_route_num / date / delivery_date_override —
     * перерахувати маршрут(и).
     */
    public function updated(OrderDay $orderDay): void
    {
        if ($orderDay->wasChanged('date')) {
            $this->syncOrder($orderDay);
        }

        if ($orderDay->wasChanged(['extra_delivery_fee', 'ant_route_num', 'date', 'delivery_date_override'])) {
            $oldExtra    = (float) $orderDay->getOriginal('extra_delivery_fee');
            $newExtra    = (float) $orderDay->extra_delivery_fee;
            $oldRouteNum = $orderDay->getOriginal('ant_route_num');
            $newRouteNum = $orderDay->ant_route_num;
            $oldDeliveryDate = $oldRouteNum ? $this->resolveOriginalDeliveryDate($orderDay) : null;
            $newDeliveryDate = $newRouteNum ? $orderDay->resolveDeliveryDate() : null;

            $sameRoute = $oldRouteNum
                && $newRouteNum
                && (int) $oldRouteNum === (int) $newRouteNum
                && $oldDeliveryDate?->startOfDay()->equalTo($newDeliveryDate->startOfDay());

            if ($sameRoute) {
                // Звичайний кейс: чекбокс на тому ж маршруті — дельта = newExtra - oldExtra.
                $this->applyExtraDelta($newRouteNum, $newDeliveryDate, $newExtra - $oldExtra);
            } else {
                // Маршрут/дата доставки змінились — старий втрачає oldExtra, новий отримує newExtra.
                if ($oldRouteNum) {
                    $this->applyExtraDelta($oldRouteNum, $oldDeliveryDate, -$oldExtra);
                }
                if ($newRouteNum) {
                    $this->applyExtraDelta($newRouteNum, $newDeliveryDate, $newExtra);
                }
            }
        }
    }

    private function resolveOriginalDeliveryDate(OrderDay $orderDay): \Carbon\Carbon
    {
        $originalOverride = $orderDay->getOriginal('delivery_date_override');
        if ($originalOverride) {
            return \Carbon\Carbon::parse($originalOverride);
        }
        $originalDate = $orderDay->getOriginal('date');
        $isEvening = \App\Services\ScheduleService::isEvening($orderDay->order?->schedule_type);
        return \App\Services\ScheduleService::computeDeliveryDate(
            \Carbon\Carbon::parse($originalDate),
            $isEvening,
        );
    }

    /**
     * При видаленні дня — той самий перерахунок
     * (щоб замовлення без майбутніх днів автоматично ставало finished).
     */
    public function deleted(OrderDay $orderDay): void
    {
        $this->syncOrder($orderDay);
        // Якщо день мав extra і був прив'язаний — зняти суму з маршруту.
        if ((float) $orderDay->extra_delivery_fee > 0 && $orderDay->ant_route_num) {
            $this->applyExtraDelta(
                $orderDay->ant_route_num,
                $orderDay->resolveDeliveryDate(),
                -(float) $orderDay->extra_delivery_fee,
            );
        }
    }

    private function syncOrder(OrderDay $orderDay): void
    {
        $order = $orderDay->order;
        if (!$order) {
            return;
        }
        $order->refresh();
        $order->recomputeEndDate();
        $order->refresh()->recomputeStatus();
    }

    /**
     * Застосувати дельту extra_delivery_fee до маршруту:
     *  - оновити DeliveryRoute.calculated_cost (повний перерахунок через recalcCost),
     *  - якщо менеджер уже відмітив зміну курьєра — зрушити EmployeeShift.rate і Employee.balance
     *    рівно на дельту, без захоплення сторонніх переоцінок базових тарифів.
     */
    private function applyExtraDelta($routeNum, ?\Carbon\Carbon $date, float $delta): void
    {
        if (!$routeNum || !$date) {
            return;
        }

        $route = DeliveryRoute::where('ant_route_num', $routeNum)
            ->whereDate('date', $date)
            ->first();

        if (!$route) {
            return;
        }

        DB::transaction(function () use ($route, $delta) {
            // Завжди вирівнюємо calculated_cost до фактичного recalcCost (base + extras).
            $newCost = $route->recalcCost();
            if (abs((float) $route->calculated_cost - $newCost) >= 0.005) {
                $route->update(['calculated_cost' => $newCost]);
            }

            if (abs($delta) < 0.005 || !$route->employee_id) {
                return;
            }

            // Мапимо ранкові/вечірні маршрути на відповідний слот зміни.
            $preferredSlots = match ($route->shift) {
                'morning' => [EmployeeShift::SLOT_MORNING, EmployeeShift::SLOT_FULL],
                'evening' => [EmployeeShift::SLOT_EVENING, EmployeeShift::SLOT_FULL],
                default   => [EmployeeShift::SLOT_FULL, EmployeeShift::SLOT_MORNING, EmployeeShift::SLOT_EVENING],
            };

            $shift = EmployeeShift::where('employee_id', $route->employee_id)
                ->whereDate('date', $route->date)
                ->whereIn('shift_slot', $preferredSlots)
                ->orderByRaw("FIELD(shift_slot, '" . implode("','", $preferredSlots) . "')")
                ->first();

            if (!$shift) {
                return;
            }

            $shift->update(['rate' => max(0, (float) $shift->rate + $delta)]);
            Employee::find($route->employee_id)?->increment('balance', $delta);
        });
    }
}