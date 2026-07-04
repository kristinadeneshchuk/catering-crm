<?php

namespace App\Observers;

use App\Models\DeliveryRoute;
use App\Services\CourierShiftPricingService;
use Carbon\Carbon;

/**
 * При будь-якій зміні маршруту курʼєра (створення / зміна вартості / зміна дати
 * / перевʼязка на іншого курʼєра / видалення) — переоцінюємо ставку відповідної
 * зміни курʼєра у Табелі. Так галочка у Табелі, поставлена ДО імпорту з ANT,
 * автоматично «оживає», коли маршрут прилітає.
 */
class DeliveryRouteObserver
{
    public function __construct(protected CourierShiftPricingService $pricing) {}

    public function created(DeliveryRoute $route): void
    {
        $this->repriceRoute($route);
    }

    public function updated(DeliveryRoute $route): void
    {
        // Якщо перевʼязали маршрут з одного курʼєра на іншого або переносили дату —
        // перерахуємо і стару, і нову зміну, щоб не лишалось «сирітських» +700 у попередника.
        if ($route->wasChanged('employee_id') || $route->wasChanged('date')) {
            $oldEmp  = $route->getOriginal('employee_id');
            $oldDate = $route->getOriginal('date');
            if ($oldEmp && $oldDate) {
                $this->pricing->reprice((int) $oldEmp, $this->ymd($oldDate));
            }
        }
        $this->repriceRoute($route);
    }

    public function deleted(DeliveryRoute $route): void
    {
        $this->repriceRoute($route);
    }

    protected function repriceRoute(DeliveryRoute $route): void
    {
        if (! $route->employee_id || ! $route->date) {
            return;
        }
        $this->pricing->reprice((int) $route->employee_id, $this->ymd($route->date));
    }

    /** DeliveryRoute->date іноді приходить рядком, іноді як Carbon — нормалізуємо. */
    protected function ymd($date): string
    {
        return $date instanceof Carbon ? $date->format('Y-m-d') : Carbon::parse($date)->format('Y-m-d');
    }
}
