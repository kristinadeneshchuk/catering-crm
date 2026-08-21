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
        if ((float) $orderDay->extra_delivery_fee > 0 && ($orderDay->ant_route_id || $orderDay->ant_route_num)) {
            $this->applyExtraDelta(
                $orderDay->ant_route_num,
                $orderDay->resolveDeliveryDate(),
                (float) $orderDay->extra_delivery_fee,
                $orderDay->ant_route_id,
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

        if ($orderDay->wasChanged(['extra_delivery_fee', 'ant_route_num', 'ant_route_id', 'date', 'delivery_date_override'])) {
            $oldExtra    = (float) $orderDay->getOriginal('extra_delivery_fee');
            $newExtra    = (float) $orderDay->extra_delivery_fee;
            $oldRouteNum = $orderDay->getOriginal('ant_route_num');
            $newRouteNum = $orderDay->ant_route_num;
            $oldRouteId  = $orderDay->getOriginal('ant_route_id');
            $newRouteId  = $orderDay->ant_route_id;
            $oldHasRoute = $oldRouteId || $oldRouteNum;
            $newHasRoute = $newRouteId || $newRouteNum;
            $oldDeliveryDate = $oldHasRoute ? $this->resolveOriginalDeliveryDate($orderDay) : null;
            $newDeliveryDate = $newHasRoute ? $orderDay->resolveDeliveryDate() : null;

            // Той самий маршрут: за стабільним ant_route_id, якщо він є з обох
            // боків, інакше — по-старому за номером (легасі-рядки без id).
            $sameKey = ($oldRouteId && $newRouteId)
                ? (string) $oldRouteId === (string) $newRouteId
                : ($oldRouteNum && $newRouteNum && (int) $oldRouteNum === (int) $newRouteNum
                    && !$oldRouteId && !$newRouteId);

            $sameRoute = $oldHasRoute
                && $newHasRoute
                && $sameKey
                && $oldDeliveryDate?->startOfDay()->equalTo($newDeliveryDate->startOfDay());

            if ($sameRoute) {
                // Звичайний кейс: чекбокс на тому ж маршруті — дельта = newExtra - oldExtra.
                $this->applyExtraDelta($newRouteNum, $newDeliveryDate, $newExtra - $oldExtra, $newRouteId);
            } else {
                // Маршрут/дата доставки змінились — старий втрачає oldExtra, новий отримує newExtra.
                if ($oldHasRoute) {
                    $this->applyExtraDelta($oldRouteNum, $oldDeliveryDate, -$oldExtra, $oldRouteId);
                }
                if ($newHasRoute) {
                    $this->applyExtraDelta($newRouteNum, $newDeliveryDate, $newExtra, $newRouteId);
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
        if ((float) $orderDay->extra_delivery_fee > 0 && ($orderDay->ant_route_id || $orderDay->ant_route_num)) {
            $this->applyExtraDelta(
                $orderDay->ant_route_num,
                $orderDay->resolveDeliveryDate(),
                -(float) $orderDay->extra_delivery_fee,
                $orderDay->ant_route_id,
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
     * Застосувати дельту extra_delivery_fee до маршруту.
     * Оновлюємо DeliveryRoute.calculated_cost — Observer маршруту сам викличе
     * CourierShiftPricingService::reprice(), яка перерахує EmployeeShift.rate
     * і скорегує баланс курʼєра з єдиної формули (base_rate × виїзди + надбавки).
     * $delta більше не потрібна — залишена в сигнатурі для сумісності викликів.
     */
    private function applyExtraDelta($routeNum, ?\Carbon\Carbon $date, float $delta, ?string $routeId = null): void
    {
        if ((!$routeNum && !$routeId) || !$date) {
            return;
        }

        // Спершу за стабільним ant_route_id — номер перенумеровується при
        // перебудові маршрутів в ANT. Номер лишається фолбеком для легасі.
        $route = null;
        if ($routeId) {
            $route = DeliveryRoute::where('ant_route_id', (string) $routeId)
                ->whereDate('date', $date)
                ->first();
        }
        if (!$route && $routeNum) {
            $route = DeliveryRoute::where('ant_route_num', $routeNum)
                ->whereDate('date', $date)
                ->first();
        }

        if (!$route) {
            return;
        }

        DB::transaction(function () use ($route) {
            $newCost = $route->recalcCost();
            if (abs((float) $route->calculated_cost - $newCost) >= 0.005) {
                // Тригерить DeliveryRouteObserver → reprice → синк shift.rate + balance.
                $route->update(['calculated_cost' => $newCost]);
            } else {
                // Cost не змінився, але extra міг помінятись всередині tie (рідкісний
                // випадок). Викликаємо reprice явно, щоб shift оновився.
                if ($route->employee_id) {
                    app(\App\Services\CourierShiftPricingService::class)
                        ->reprice((int) $route->employee_id, \Carbon\Carbon::parse($route->date)->format('Y-m-d'), $route->shift);
                }
            }
        });
    }
}