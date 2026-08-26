<?php

namespace App\Services;

use App\Models\RouteStop;
use App\Models\SmsLog;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Сповіщення клієнтів про курʼєра після побудови маршрутів.
 *
 * Джерело даних — архів route_stops, а не живі order_days. Причина в тому, як
 * влаштований ANT: на дату там вміщується один комплект маршрутів, тож логіст,
 * будуючи вечірні, видаляє ранкові. У живих даних обидві зміни одночасно не
 * існують ніколи — а розсилка потребує саме обох: увечері шлемо і за сьогоднішній
 * вечір, і за завтрашній ранок.
 *
 * У знімку курʼєр, авто, телефон і адреса вже прибиті до точки на момент виїзду,
 * тому тут не лишилось ні матчингу «номер маршруту + водій», ні здогадок про
 * зміну.
 */
class CourierSmsNotifier
{
    /** Увечері: сьогоднішній вечір + завтрашній ранок, одним списком. */
    public const SHIFT_EVENING_PLUS_MORNING = 'evening_and_next_morning';

    /** Розсилати можна лише вдень — «з 09:00 можна розсилки робити за законом». */
    public const KEY_WINDOW_FROM = 'sms_window_from';
    public const KEY_WINDOW_TO   = 'sms_window_to';

    public const DEFAULT_WINDOW_FROM = '09:00';
    public const DEFAULT_WINDOW_TO   = '21:00';

    public function __construct(
        private TurboSmsService $sms,
    ) {
    }

    /**
     * Відрізки, з яких складається розсилка: [дата доставки, зміна].
     *
     * @return array<int, array{0: string, 1: string}>
     */
    public function segments(string $date, string $shift): array
    {
        $date = Carbon::parse($date)->format('Y-m-d');

        if ($shift === self::SHIFT_EVENING_PLUS_MORNING) {
            return [
                [$date, 'evening'],
                [Carbon::parse($date)->addDay()->format('Y-m-d'), 'morning'],
            ];
        }

        return [[$date, $shift]];
    }

    /**
     * Чи можна зараз відправляти. Вікно налаштовується в CRM.
     *
     * @return array{allowed: bool, from: string, to: string}
     */
    public function timeWindow(): array
    {
        $from = trim((string) (\App\Models\Setting::where('key', self::KEY_WINDOW_FROM)->value('value') ?: self::DEFAULT_WINDOW_FROM));
        $to   = trim((string) (\App\Models\Setting::where('key', self::KEY_WINDOW_TO)->value('value') ?: self::DEFAULT_WINDOW_TO));

        $now = now()->format('H:i');

        return [
            'allowed' => $now >= $from && $now <= $to,
            'from'    => $from,
            'to'      => $to,
        ];
    }

    // -------------------------------------------------------------------------
    // Готовність до відправки (стан кнопки)
    // -------------------------------------------------------------------------

    /**
     * Кнопка активна, щойно є хоч одна точка з курʼєром: точки з неповними
     * даними не блокують решту — вони показуються списком у модалці. Повне
     * блокування лишається тільки коли точок немає взагалі, немає жодного
     * курʼєра або SMS не налаштовано.
     *
     * @return array{ready: bool, reason: ?string, warning: ?string, routes: int, routes_without_courier: int}
     */
    public function readiness(string $date, string $shift = 'all'): array
    {
        $stops = $this->stopsFor($date, $shift);

        $routes = $stops->pluck('ant_route_id')->filter()->unique()->count()
            ?: ($stops->isEmpty() ? 0 : 1);

        $withoutCourier = $stops->filter(fn (RouteStop $s) => ! $s->courier_name)->count();

        $result = [
            'routes'                 => $routes,
            'routes_without_courier' => $withoutCourier,
            'warning'                => null,
        ];

        if ($stops->isEmpty()) {
            return $result + [
                'ready'  => false,
                'reason' => 'Точки ще не завантажені в CRM. Натисніть «Точки маршрутів», щоб зняти їх з ANT.',
            ];
        }

        if ($withoutCourier === $stops->count()) {
            return $result + [
                'ready'  => false,
                'reason' => 'На жодній точці немає курʼєра. Звірте імʼя в ANT з полем «Імʼя в ANT Logistics» у картці курʼєра і завантажте точки ще раз.',
            ];
        }

        if ($error = $this->sms->configurationError()) {
            return $result + ['ready' => false, 'reason' => $error];
        }

        $window = $this->timeWindow();

        if (! $window['allowed']) {
            return $result + [
                'ready'  => false,
                'reason' => "Зараз не можна відправляти: вікно розсилки {$window['from']}–{$window['to']}.",
            ];
        }

        if ($withoutCourier > 0) {
            $result['warning'] = "Без курʼєра: {$withoutCourier} точ(ок) — ці клієнти SMS не отримають, "
                . 'вони будуть у списку «проблемних» перед відправкою.';
        }

        return $result + ['ready' => true, 'reason' => null];
    }

    // -------------------------------------------------------------------------
    // Превʼю: кому підемо і що не так
    // -------------------------------------------------------------------------

    /**
     * Збирає отримувачів та проблемні точки, нічого не відправляючи.
     *
     * @return array{
     *     recipients: array<int, array<string, mixed>>,
     *     problems: array<int, array{client: string, order_id: ?int, reason: string}>,
     *     new: int, resend: int, unchanged: int
     * }
     */
    public function preview(string $date, string $shift = 'all'): array
    {
        $template   = $this->sms->template();
        $recipients = [];
        $problems   = [];

        foreach ($this->stopsFor($date, $shift) as $stop) {
            $clientName = $stop->client_name ?: ('Замовлення #' . ($stop->order_id ?? '—'));

            // Ключ по замовленню+причині: клієнт з кількома точками не має
            // задвоюватись у списку проблем.
            $fail = function (string $reason) use (&$problems, $clientName, $stop): void {
                $problems[$stop->order_id . '|' . $reason] = [
                    'client'   => $clientName,
                    'order_id' => $stop->order_id,
                    'reason'   => $reason,
                ];
            };

            $rawPhone = trim((string) ($stop->client_phone ?? ''));

            if ($rawPhone === '') {
                $fail('У клієнта не вказано телефон');
                continue;
            }

            $phone = TurboSmsService::normalizePhone($rawPhone);

            if ($phone === null) {
                $fail("Некоректний номер телефону клієнта: {$rawPhone}");
                continue;
            }

            if (! $stop->courier_name) {
                $fail('На маршруті №' . ($stop->ant_route_num ?: '—') . ' не призначено курʼєра');
                continue;
            }

            $courierPhone = TurboSmsService::normalizePhone($stop->courier_phone);

            if ($courierPhone === null) {
                $fail("У курʼєра «{$stop->courier_name}» не вказано (або некоректний) телефон");
                continue;
            }

            $car = trim((string) ($stop->car_number ?? ''));

            if ($car === '') {
                $fail('На маршруті №' . ($stop->ant_route_num ?: '—') . ' не вказано номер авто');
                continue;
            }

            $courierName = $this->courierDisplayName($stop->courier_name);
            $fingerprint = $this->fingerprint($courierName, $courierPhone, $car);

            // Ключ «дата + номер + курʼєр». Клієнт, у якого і вечірня, і
            // ранкова доставка різними курʼєрами, має отримати обидві SMS —
            // інакше про другого курʼєра йому ніхто не скаже.
            $key = $this->stopDate($stop) . '|' . $phone . '|' . $fingerprint;

            if (isset($recipients[$key])) {
                continue;
            }

            $recipients[$key] = [
                'key'           => $key,
                'date'          => $this->stopDate($stop),
                'shift'         => $stop->shift ?: 'all',
                'phone'         => $phone,
                'client_id'     => $stop->client_id,
                'client_name'   => $stop->client_name,
                'order_id'      => $stop->order_id,
                'order_day_id'  => $stop->order_day_id,
                'route_num'     => $stop->ant_route_num,
                'courier_name'  => $courierName,
                'courier_phone' => $courierPhone,
                'car_number'    => $car,
                'fingerprint'   => $fingerprint,
                'text'          => $this->renderText($template, $courierName, $courierPhone, $car, $stop->client_name),
            ];
        }

        $recipients = $this->markAlreadySent($recipients);

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
     * @param  ?array<int, string>  $onlyKeys  ключі отримувачів (recipient['key']), яким слати;
     *                                         null — усім. Використовується для тестової
     *                                         відправки 1-2 клієнтам з модалки.
     * @return array{sent: int, failed: int, skipped: int, problems: array, errors: array<int, string>}
     */
    public function send(string $date, string $shift = 'all', bool $resendAll = false, ?array $onlyKeys = null): array
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
                    'sent' => 0, 'failed' => 0, 'skipped' => 0, 'excluded' => 0, 'problems' => [],
                    'errors' => ['Відправка вже виконується — зачекайте, поки завершиться попередня.'],
                ];
            }
        } catch (\Throwable $e) {
            // Драйвер кешу без підтримки локів — працюємо без блокування.
            Log::warning('[CourierSms] Lock unavailable', ['error' => $e->getMessage()]);
            $lock = null;
        }

        try {
            return $this->performSend($deliveryDate, $shift, $resendAll, $onlyKeys);
        } finally {
            $lock?->release();
        }
    }

    /**
     * @return array{sent: int, failed: int, skipped: int, problems: array, errors: array<int, string>}
     */
    private function performSend(string $deliveryDate, string $shift, bool $resendAll, ?array $onlyKeys = null): array
    {
        // Вікно перевіряємо і тут, а не лише в readiness: відправку можна
        // запустити з відкритої модалки, коли час уже вийшов.
        $window = $this->timeWindow();

        if (! $window['allowed']) {
            return [
                'sent' => 0, 'failed' => 0, 'skipped' => 0, 'excluded' => 0, 'problems' => [],
                'errors' => ["Зараз не можна відправляти: вікно розсилки {$window['from']}–{$window['to']}."],
            ];
        }

        $preview = $this->preview($deliveryDate, $shift);

        // За замовчуванням не турбуємо клієнтів, у яких нічого не змінилось.
        $eligible = array_filter(
            $preview['recipients'],
            fn (array $r) => $resendAll || ! $r['already_sent'] || $r['changed'],
        );

        // Ручний вибір отримувачів (тестова відправка 1-2 клієнтам).
        $queue = $onlyKeys === null
            ? $eligible
            : array_filter($eligible, fn (array $r) => in_array($r['key'], $onlyKeys, true));

        $skipped  = count($preview['recipients']) - count($eligible);
        $excluded = count($eligible) - count($queue);

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
                    $this->writeLog($r['date'], $r['shift'], $r, SmsLog::STATUS_FAILED, $globalRow, $result['error'], $userId, $result['raw']);
                    $failed++;
                }
                continue;
            }

            foreach ($group as $r) {
                $row = $result['results'][$r['phone']] ?? null;

                if ($row === null) {
                    $errors[] = "{$r['client_name']} ({$r['phone']}): TurboSMS не повернув статус";
                    $this->writeLog($r['date'], $r['shift'], $r, SmsLog::STATUS_FAILED, null, 'TurboSMS не повернув статус по номеру', $userId, $result['raw']);
                    $failed++;
                    continue;
                }

                if ($row['ok']) {
                    $this->writeLog($r['date'], $r['shift'], $r, SmsLog::STATUS_SENT, $row, null, $userId, $result['raw']);
                    $sent++;
                } else {
                    $errors[] = "{$r['client_name']} ({$r['phone']}): {$row['message']}";
                    $this->writeLog($r['date'], $r['shift'], $r, SmsLog::STATUS_FAILED, $row, $row['message'], $userId, $result['raw']);
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
            'excluded' => $excluded,
            'problems' => $preview['problems'],
            'errors'   => array_values(array_unique($errors)),
        ];
    }

    // -------------------------------------------------------------------------
    // Внутрішнє
    // -------------------------------------------------------------------------

    /**
     * Точки, за якими шлемо. Кілька відрізків — коли ввечері беремо і
     * сьогоднішній вечір, і завтрашній ранок.
     *
     * @return \Illuminate\Support\Collection<int, RouteStop>
     */
    private function stopsFor(string $date, string $shift): \Illuminate\Support\Collection
    {
        $stops = collect();

        foreach ($this->segments($date, $shift) as [$segDate, $segShift]) {
            $stops = $stops->merge(
                RouteStop::forDelivery($segDate, $segShift)
                    ->orderBy('ant_route_num')
                    ->orderBy('position')
                    ->get()
            );
        }

        return $stops;
    }

    private function stopDate(RouteStop $stop): string
    {
        return $stop->date instanceof \Carbon\Carbon
            ? $stop->date->format('Y-m-d')
            : Carbon::parse((string) $stop->date)->format('Y-m-d');
    }

    /**
     * Імʼя курʼєра для SMS: без службової позначки «(курʼєр)» і не довше двох слів.
     *
     * У картці співробітника імена ведуться для зарплат і матчингу з ANT — там
     * лежить «Личко Володимир Валерійович(кур'єр)». Дослівно в SMS це дає 85
     * символів при ліміті 70 кирилицею, тобто кожне повідомлення тарифікується
     * як два, а клієнт ще й бачить внутрішню позначку.
     */
    private function courierDisplayName(string $name): string
    {
        // (кур'єр), (курʼєр) тощо
        $clean = preg_replace('/\([^)]*\)/u', ' ', $name);
        // те саме слово без дужок
        $clean = preg_replace('/\bкур[\x{02BC}\x{2019}\x{0027}\x{044C}]?[\x{0454}\x{0435}]р\w*/iu', ' ', (string) $clean);
        $clean = trim(preg_replace('/\s+/u', ' ', (string) $clean));

        // Якщо після чистки нічого не лишилось — краще довге імʼя, ніж порожнє.
        if ($clean === '') {
            return trim($name);
        }

        return implode(' ', array_slice(preg_split('/\s+/u', $clean), 0, 2));
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
     * Позначає отримувачів, яким уже слали SMS:
     *   already_sent — слали взагалі;
     *   changed      — слали, але з іншим курʼєром/авто (маршрути перебудували).
     *
     * Звіряємо парою «дата + телефон»: розсилка може охоплювати два дні
     * (вечір сьогодні + ранок завтра), і вчорашня відправка не має гасити
     * сьогоднішню.
     */
    private function markAlreadySent(array $recipients): array
    {
        if (empty($recipients)) {
            return $recipients;
        }

        $dates  = array_values(array_unique(array_column($recipients, 'date')));
        $phones = array_values(array_unique(array_column($recipients, 'phone')));

        // whereDate, а не whereIn: колонка date приходить з БД як datetime,
        // і пряме порівняння з 'Y-m-d' не збігається.
        $logs = SmsLog::sent()
            ->where(function ($q) use ($dates) {
                foreach ($dates as $d) {
                    $q->orWhereDate('date', $d);
                }
            })
            ->whereIn('phone', $phones)
            ->get()
            ->groupBy(fn (SmsLog $l) => Carbon::parse((string) $l->date)->format('Y-m-d') . '|' . $l->phone);

        foreach ($recipients as $key => $r) {
            $sentLogs = $logs->get($r['date'] . '|' . $r['phone']);

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
