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
        $this->syncRouteCost($orderDay);
    }

    /**
     * Якщо у дня змінилась дата — підлаштувати end_date парента.
     * Якщо змінилась extra_delivery_fee / ant_route_num / date — перерахувати маршрут(и).
     */
    public function updated(OrderDay $orderDay): void
    {
        if ($orderDay->wasChanged('date')) {
            $this->syncOrder($orderDay);
        }

        if ($orderDay->wasChanged(['extra_delivery_fee', 'ant_route_num', 'date'])) {
            // Перерахувати старий маршрут (якщо переприв'язали) і новий.
            $oldRouteNum = $orderDay->getOriginal('ant_route_num');
            $oldDate     = $orderDay->getOriginal('date');
            if ($oldRouteNum && $oldDate &&
                ($oldRouteNum !== $orderDay->ant_route_num || (string) $oldDate !== (string) $orderDay->date)
            ) {
                $this->recalcRouteByKey($oldRouteNum, $oldDate);
            }
            $this->syncRouteCost($orderDay);
        }
    }

    /**
     * При видаленні дня — той самий перерахунок
     * (щоб замовлення без майбутніх днів автоматично ставало finished).
     */
    public function deleted(OrderDay $orderDay): void
    {
        $this->syncOrder($orderDay);
        $this->syncRouteCost($orderDay);
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
     * Перерахувати calculated_cost маршруту, якому належить цей OrderDay,
     * та синхронізувати зміну в табелі (EmployeeShift.rate + Employee.balance).
     */
    private function syncRouteCost(OrderDay $orderDay): void
    {
        $this->recalcRouteByKey($orderDay->ant_route_num, $orderDay->date);
    }

    private function recalcRouteByKey(?string $routeNum, $date): void
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

        $oldCost = (float) $route->calculated_cost;
        $newCost = $route->recalcCost();
        $delta   = round($newCost - $oldCost, 2);

        if (abs($delta) < 0.005) {
            return;
        }

        DB::transaction(function () use ($route, $newCost, $delta) {
            $route->update(['calculated_cost' => $newCost]);

            if (!$route->employee_id) {
                return;
            }

            // Якщо менеджер уже відмітив зміну курьєра на цю дату — донести дельту в rate і balance.
            // Маршрутів на дату може бути декілька → дельта саме цього маршруту переноситься 1:1.
            // Дальня доставка — окрема плата, не «половина зміни», тому is_half не ділимо.
            $shift = EmployeeShift::where('employee_id', $route->employee_id)
                ->whereDate('date', $route->date)
                ->first();

            if (!$shift) {
                return;
            }

            $shift->update(['rate' => max(0, (float) $shift->rate + $delta)]);

            $employee = Employee::find($route->employee_id);
            $employee?->increment('balance', $delta);
        });
    }
}