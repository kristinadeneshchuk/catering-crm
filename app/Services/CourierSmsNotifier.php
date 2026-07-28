<?php

namespace App\Services;

use App\Models\DeliveryRoute;
use App\Models\OrderDay;
use App\Models\SmsLog;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Сповіщення клієнтів про курʼєра після побудови маршрутів.
 *
 * Ланцюжок звʼязків:
 *   OrderDay (ant_route_num + ant_driver) → DeliveryRoute → Employee (курʼєр)
 *   Клієнт: OrderDay → Order → Client (phone)
 *   Авто:   DeliveryRoute.registration_number (приходить з ANT)
 *
 * Номер маршруту НЕ унікальний у межах дня (ранковий і вечірній прогони обидва
 * нумеруються з 1), тому матчимо парою «номер + водій» — той самий підхід, що і
 * в DeliveryRoute::extraDeliveryFee().
 */
class CourierSmsNotifier
{
    public function __construct(
        private TurboSmsService $sms,
        private AntLogisticsService $ant,
    ) {
    }

    // -------------------------------------------------------------------------
    // Готовність до відправки (стан кнопки)
    // -------------------------------------------------------------------------

    /**
     * @return array{ready: bool, reason: ?string, routes: int, routes_without_courier: int}
     */
    public function readiness(string $date, string $shift = 'all'): array
    {
        $routes = $this->routesFor($date, $shift);

        $withoutCourier = $routes->filter(fn (DeliveryRoute $r) => ! $r->employee_id)->count();

        $result = [
            'routes'                 => $routes->count(),
            'routes_without_courier' => $withoutCourier,
        ];

        if ($routes->isEmpty()) {
            return $result + ['ready' => false, 'reason' => 'Маршрути ще не побудовані. Натисніть «Точки ↓», щоб завантажити їх з ANT.'];
        }

        if ($withoutCourier > 0) {
            return $result + ['ready' => false, 'reason' => "Не призначено курʼєра на {$withoutCourier} маршрут(ів). Звірте імʼя в ANT з полем «Імʼя в ANT Logistics» у картці курʼєра."];
        }

        if ($error = $this->sms->configurationError()) {
            return $result + ['ready' => false, 'reason' => $error];
        }

        return $result + ['ready' => true, 'reason' => null];
    }

    // -------------------------------------------------------------------------
    // Превʼю: кому підемо і що не так
    // -------------------------------------------------------------------------

    /**
     * Збирає отримувачів та проблемні замовлення, нічого не відправляючи.
     *
     * @return array{
     *     recipients: array<int, array<string, mixed>>,
     *     problems: array<int, array{client: string, order_id: ?int, reason: string}>,
     *     new: int, resend: int, unchanged: int
     * }
     */
    public function preview(string $date, string $shift = 'all'): array
    {
        $deliveryDate = Carbon::parse($date)->format('Y-m-d');

        $routes    = $this->routesFor($deliveryDate, $shift);
        $routeMap  = $this->buildRouteMap($routes);
        $days      = $this->ant->collectOrderDaysForDelivery($deliveryDate, $shift)['days'];

        $template = $this->sms->template();

        $recipients = [];
        $problems   = [];

        foreach ($days as $day) {
            $order  = $day->order;
            $client = $order?->client;

            $clientName = $client?->name ?: ('Замовлення #' . ($order?->id ?? '—'));
            $orderId    = $order?->id;

            // Ключ по замовленню+причині: клієнт з кількома днями в одній
            // доставці не має задвоюватись у списку проблем.
            $fail = function (string $reason) use (&$problems, $clientName, $orderId): void {
                $problems[$orderId . '|' . $reason] = [
                    'client'   => $clientName,
                    'order_id' => $orderId,
                    'reason'   => $reason,
                ];
            };

            if (! $client) {
                $fail('Замовлення без клієнта');
                continue;
            }

            $rawPhone = trim((string) ($client->phone ?? ''));
            if ($rawPhone === '') {
                $fail('У клієнта не вказано телефон');
                continue;
            }

            $phone = TurboSmsService::normalizePhone($rawPhone);
            if ($phone === null) {
                $fail("Некоректний номер телефону клієнта: {$rawPhone}");
                continue;
            }

            $routeNum = (int) ($day->ant_route_num ?? 0);
            if (! $routeNum) {
                $fail('Замовлення не привʼязане до маршруту');
                continue;
            }

            $route = $this->matchRoute($routeMap, $routeNum, $day->ant_driver, $day);
            if (! $route) {
                $fail("Не вдалося однозначно визначити маршрут №{$routeNum}"
                    . ($day->ant_driver ? " (водій «{$day->ant_driver}»)" : '')
                    . '. Перезавантажте маршрути або оберіть конкретну зміну.');
                continue;
            }

            $courier = $route->employee;
            if (! $courier) {
                $fail("На маршруті №{$routeNum} не призначено курʼєра");
                continue;
            }

            $courierPhone = TurboSmsService::normalizePhone($courier->phone);
            if ($courierPhone === null) {
                $fail("У курʼєра «{$courier->name}» не вказано (або некоректний) телефон");
                continue;
            }

            $car = trim((string) ($route->registration_number ?? ''));
            if ($car === '') {
                $fail("На маршруті №{$routeNum} не вказано номер авто");
                continue;
            }

            $fingerprint = $this->fingerprint($courier->name, $courierPhone, $car);

            // Ключ «номер + курʼєр», а не самий номер. Подвійний раціон (кілька
            // днів на одному маршруті) дає однаковий fingerprint і згортається в
            // одну SMS. А от коли в клієнта і ранкова, і вечірня доставка різними
            // курʼєрами — це два різні fingerprint, і він має отримати обидві,
            // інакше про другого курʼєра йому ніхто не скаже.
            $key = $phone . '|' . $fingerprint;

            if (isset($recipients[$key])) {
                continue;
            }

            $recipients[$key] = [
                'phone'         => $phone,
                'client_id'     => $client->id,
                'client_name'   => $client->name,
                'order_id'      => $orderId,
                'order_day_id'  => $day->id,
                'route_num'     => $routeNum,
                'courier_name'  => $courier->name,
                'courier_phone' => $courierPhone,
                'car_number'    => $car,
                'fingerprint'   => $fingerprint,
                'text'          => $this->renderText($template, $courier->name, $courierPhone, $car, $client->name),
            ];
        }

        // Позначаємо, кому вже слали таку саму інформацію.
        $recipients = $this->markAlreadySent($deliveryDate, $recipients);

        $new       = 0;
        $resend    = 0;
        $unchanged = 0;

        foreach ($recipients as $r) {
            if (! $r['already_sent']) {
                $new++;
            } elseif ($r['changed']) {
                $resend++;
            } else {
                $unchanged++;
            }
        }

        return [
            'recipients' => array_values($recipients),
            'problems'   => array_values($problems),
            'new'        => $new,
            'resend'     => $resend,
            'unchanged'  => $unchanged,
        ];
    }

    // -------------------------------------------------------------------------
    // Відправка
    // -------------------------------------------------------------------------

    /**
     * @param  bool  $resendAll  true — слати всім, включно з тими, кому вже слали
     *                           ту саму інформацію (ручне підтвердження адміністратора).
     * @return array{sent: int, failed: int, skipped: int, problems: array, errors: array<int, string>}
     */
    public function send(string $date, string $shift = 'all', bool $resendAll = false): array
    {
        $deliveryDate = Carbon::parse($date)->format('Y-m-d');

        // Захист від подвійного натискання / двох вкладок: без нього обидва
        // виклики порахували б «ще не слали» і клієнт отримав би дві SMS.
        // Ключ — тільки дата, без зміни: відправка по 'all' і по 'morning'
        // перетинаються за отримувачами, тож серіалізуємо їх разом.
        $lock = null;
        try {
            $lock = Cache::lock("courier-sms:{$deliveryDate}", 300);

            if (! $lock->get()) {
                return [
                    'sent' => 0, 'failed' => 0, 'skipped' => 0, 'problems' => [],
                    'errors' => ['Відправка вже виконується — зачекайте, поки завершиться попередня.'],
                ];
            }
        } catch (\Throwable $e) {
            // Драйвер кешу без підтримки локів — працюємо без блокування.
            Log::warning('[CourierSms] Lock unavailable', ['error' => $e->getMessage()]);
            $lock = null;
        }

        try {
            return $this->performSend($deliveryDate, $shift, $resendAll);
        } finally {
            $lock?->release();
        }
    }

    /**
     * @return array{sent: int, failed: int, skipped: int, problems: array, errors: array<int, string>}
     */
    private function performSend(string $deliveryDate, string $shift, bool $resendAll): array
    {
        $preview = $this->preview($deliveryDate, $shift);

        // За замовчуванням не турбуємо клієнтів, у яких нічого не змінилось.
        $queue = array_filter(
            $preview['recipients'],
            fn (array $r) => $resendAll || ! $r['already_sent'] || $r['changed'],
        );

        $skipped = count($preview['recipients']) - count($queue);

        $sent   = 0;
        $failed = 0;
        $errors = [];

        // Текст однаковий для всіх клієнтів одного маршруту — шлемо пакетом,
        // один HTTP-запит на маршрут замість одного на клієнта.
        $byText = [];
        foreach ($queue as $r) {
            $byText[$r['text']][] = $r;
        }

        $userId = auth()->id();

        foreach ($byText as $text => $group) {
            $phones = array_column($group, 'phone');
            $result = $this->sms->sendBatch($phones, $text);

            if (! $result['ok']) {
                // Глобальна помилка (немає коштів / невірний токен) — жодне
                // повідомлення групи не пішло. Код відмови теж пишемо в лог,
                // інакше саме в найважливіших випадках він лишався б порожнім.
                $errors[] = $result['error'];

                $globalRow = $result['global'] ? [
                    'code'   => $result['global']['code'],
                    'status' => $result['global']['status'],
                ] : null;

                foreach ($group as $r) {
                    $this->writeLog($deliveryDate, $shift, $r, SmsLog::STATUS_FAILED, $globalRow, $result['error'], $userId, $result['raw']);
                    $failed++;
                }
                continue;
            }

            foreach ($group as $r) {
                $row = $result['results'][$r['phone']] ?? null;

                if ($row === null) {
                    $errors[] = "{$r['client_name']} ({$r['phone']}): TurboSMS не повернув статус";
                    $this->writeLog($deliveryDate, $shift, $r, SmsLog::STATUS_FAILED, null, 'TurboSMS не повернув статус по номеру', $userId, $result['raw']);
                    $failed++;
                    continue;
                }

                if ($row['ok']) {
                    $this->writeLog($deliveryDate, $shift, $r, SmsLog::STATUS_SENT, $row, null, $userId, $result['raw']);
                    $sent++;
                } else {
                    $errors[] = "{$r['client_name']} ({$r['phone']}): {$row['message']}";
                    $this->writeLog($deliveryDate, $shift, $r, SmsLog::STATUS_FAILED, $row, $row['message'], $userId, $result['raw']);
                    $failed++;
                }
            }
        }

        Log::info('[CourierSms] Notifications sent', [
            'date'    => $deliveryDate,
            'shift'   => $shift,
            'sent'    => $sent,
            'failed'  => $failed,
            'skipped' => $skipped,
        ]);

        return [
            'sent'     => $sent,
            'failed'   => $failed,
            'skipped'  => $skipped,
            'problems' => $preview['problems'],
            'errors'   => array_values(array_unique($errors)),
        ];
    }

    // -------------------------------------------------------------------------
    // Внутрішнє
    // -------------------------------------------------------------------------

    /**
     * Маршрути на дату. Фільтр по зміні — такий самий, як на сторінці Логістики,
     * щоб кнопка відповідала тому, що адміністратор бачить на екрані.
     */
    private function routesFor(string $date, string $shift): \Illuminate\Support\Collection
    {
        $query = DeliveryRoute::whereDate('date', Carbon::parse($date)->format('Y-m-d'));

        if ($shift !== 'all') {
            $query->where('shift', $shift);
        }

        return $query->with('employee')->get();
    }

    /**
     * Обидва індекси — саме групування, а не «останній перезаписує».
     * Один курʼєр цілком може вести і ранковий, і вечірній маршрут №1 у той
     * самий день: якби ми клали їх в один ключ, частина клієнтів отримала б
     * номер чужого авто.
     *
     * @return array{by_num_driver: array<string, \Illuminate\Support\Collection>, by_num: array<int, \Illuminate\Support\Collection>}
     */
    private function buildRouteMap(\Illuminate\Support\Collection $routes): array
    {
        return [
            'by_num_driver' => $routes
                ->filter(fn (DeliveryRoute $r) => (int) $r->ant_route_num && trim((string) $r->driver_name) !== '')
                ->groupBy(fn (DeliveryRoute $r) => (int) $r->ant_route_num . '|' . mb_strtolower(trim((string) $r->driver_name)))
                ->all(),
            'by_num' => $routes
                ->filter(fn (DeliveryRoute $r) => (int) $r->ant_route_num)
                ->groupBy(fn (DeliveryRoute $r) => (int) $r->ant_route_num)
                ->all(),
        ];
    }

    /**
     * Матч «номер маршруту + водій». Якщо кандидатів кілька (той самий курʼєр
     * має ранковий і вечірній прогони з однаковим номером) — розрізняємо їх за
     * зміною конкретного дня замовлення. Якщо однозначності немає — повертаємо
     * null, і замовлення потрапляє в «проблемні»: краще не відправити SMS, ніж
     * відправити клієнту чужий телефон і номер авто.
     */
    private function matchRoute(array $map, int $routeNum, ?string $driver, OrderDay $day): ?DeliveryRoute
    {
        $driverKey  = mb_strtolower(trim((string) $driver));
        $candidates = null;

        if ($driverKey !== '') {
            $candidates = $map['by_num_driver'][$routeNum . '|' . $driverKey] ?? null;
        }

        // Fallback на самий номер — коли в OrderDay не записаний водій (легасі-рядки).
        if ($candidates === null || $candidates->isEmpty()) {
            $candidates = $map['by_num'][$routeNum] ?? null;
        }

        if ($candidates === null || $candidates->isEmpty()) {
            return null;
        }

        if ($candidates->count() === 1) {
            return $candidates->first();
        }

        // Кілька кандидатів — розводимо за зміною (ранок / вечір).
        $dayIsEvening = $this->ant->orderDayIsEvening($day);

        $matched = $candidates->filter(
            fn (DeliveryRoute $r) => $this->routeIsEvening($r) === $dayIsEvening
        );

        return $matched->count() === 1 ? $matched->first() : null;
    }

    /**
     * Зміна маршруту: спершу з колонки shift, інакше — з часу початку.
     * Поріг 14:00 — той самий, що і в AntLogisticsService::pullRouteDetails().
     * null — визначити не вдалося.
     */
    private function routeIsEvening(DeliveryRoute $route): ?bool
    {
        $shift = (string) $route->shift;

        if ($shift === 'morning') {
            return false;
        }

        if ($shift === 'evening') {
            return true;
        }

        // route_time_b приходить з ANT у форматі 'd.m.Y H:i'
        if (preg_match('/\s(\d{1,2}):/', (string) $route->route_time_b, $m)) {
            return (int) $m[1] >= 14;
        }

        return null;
    }

    /**
     * Знімок саме тієї інформації, що йде в SMS. Номер маршруту сюди НЕ входить
     * навмисно: у тексті його немає, тож два маршрути з тим самим курʼєром і
     * авто — це для клієнта одна й та сама SMS, і дублювати її не треба.
     */
    private function fingerprint(string $courierName, string $courierPhone, string $car): string
    {
        return sha1(implode('|', [mb_strtolower(trim($courierName)), $courierPhone, mb_strtolower(trim($car))]));
    }

    /**
     * Позначає отримувачів, яким уже слали SMS на цю дату:
     *   already_sent — слали взагалі;
     *   changed      — слали, але з іншим курʼєром/авто (маршрути перебудували).
     */
    private function markAlreadySent(string $date, array $recipients): array
    {
        if (empty($recipients)) {
            return $recipients;
        }

        $logs = SmsLog::sent()
            ->whereDate('date', $date)
            ->whereIn('phone', array_values(array_unique(array_column($recipients, 'phone'))))
            ->get()
            ->groupBy('phone');

        foreach ($recipients as $key => $r) {
            $sentLogs = $logs->get($r['phone']);

            $recipients[$key]['already_sent'] = (bool) $sentLogs?->isNotEmpty();
            $recipients[$key]['changed'] = $sentLogs && $sentLogs->isNotEmpty()
                ? ! $sentLogs->contains('fingerprint', $r['fingerprint'])
                : false;
        }

        return $recipients;
    }

    private function renderText(string $template, string $courier, string $courierPhone, string $car, ?string $clientName): string
    {
        return strtr($template, [
            '{courier}' => $courier,
            '{phone}'   => '+' . $courierPhone,
            '{car}'     => $car,
            '{client}'  => $clientName ?? '',
        ]);
    }

    private function writeLog(
        string $date,
        string $shift,
        array $recipient,
        string $status,
        ?array $apiRow,
        ?string $error,
        ?int $userId,
        ?string $raw = null,
    ): void {
        SmsLog::create([
            'date'            => $date,
            'shift'           => $shift,
            'order_id'        => $recipient['order_id'],
            'order_day_id'    => $recipient['order_day_id'],
            'client_id'       => $recipient['client_id'],
            'client_name'     => $recipient['client_name'],
            'phone'           => $recipient['phone'],
            'courier_name'    => $recipient['courier_name'],
            'courier_phone'   => $recipient['courier_phone'],
            'car_number'      => $recipient['car_number'],
            'text'            => $recipient['text'],
            'status'          => $status,
            'response_code'   => $apiRow['code']       ?? null,
            'response_status' => $apiRow['status']     ?? null,
            'message_id'      => $apiRow['message_id'] ?? null,
            'error'           => $error,
            // Обрізаємо: сира відповідь потрібна для розбору, а не для архіву.
            'response_body'   => $raw === null ? null : mb_substr($raw, 0, 2000),
            'fingerprint'     => $recipient['fingerprint'],
            'user_id'         => $userId,
        ]);
    }
}
