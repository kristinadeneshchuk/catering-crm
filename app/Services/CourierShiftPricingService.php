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
        // ВІДКЛЮЧЕНО з переходом на модель "base_rate = ціна одного виїзду".
        // Ставка кур'єра тепер керується виключно кліком у Табелі:
        //   full = 2 × base_rate (2 виїзди), half = base_rate (1 виїзд).
        // Маршрути з ANT не переоцінюють зміну автоматично — інакше після синку
        // ставка, поставлена менеджером вручну, затиралася б сумою маршрутів.
        // (Доплати за "дальню доставку" все ще додаються через OrderDayObserver.)
        return false;
    }
}
