<?php

namespace App\Services\Couriers;

use App\Models\CourierMileageLog;
use App\Models\CourierShiftReport;
use App\Models\DeliveryRoute;
use App\Models\Employee;
use App\Models\Order;
use App\Models\PaymentClaim;
use App\Models\RouteStop;
use App\Services\Payments\PaymentClaimService;
use App\Services\TelegramService;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Звіт зміни курʼєра — готовий шаблон, а не покрокове опитування.
 *
 * Курʼєри не відповідають на питання по одному. Тому бот сам надсилає
 * заповнений шаблон — усе, що система вже знає, вписано, — а курʼєр дописує
 * лише порожні місця і шле назад одним повідомленням разом із фото.
 */
class CourierReportService
{
    /** Порожнє поле в шаблоні. */
    public const BLANK = '______';

    /**
     * Запасна межа пробігу за зміну, коли в маршруту немає плану з ANT
     * (distance_calc заповнений лише в невеликої частини маршрутів).
     */
    public const MAX_KM_WITHOUT_PLAN = 400;

    public function __construct(
        private TelegramService $telegram,
        private CourierMileageService $mileage,
        private PaymentClaimService $claims,
        private CourierPayoutService $payouts,
    ) {
    }

    // -------------------------------------------------------------------------
    // Шаблон
    // -------------------------------------------------------------------------

    /**
     * Розіслати шаблони на зміну всім курʼєрам, у кого є маршрут і підключений бот.
     *
     * @return int скільки надіслано
     */
    public function sendTemplates(string $date, string $slot): int
    {
        $sent = 0;

        foreach ($this->couriersOnShift($date, $slot) as $employee) {
            if ($this->sendTemplate($employee, $date, $slot)) {
                $sent++;
            }
        }

        return $sent;
    }

    public function sendTemplate(Employee $employee, string $date, string $slot): ?CourierShiftReport
    {
        if (! $employee->telegram_chat_id) {
            return null;
        }

        $existing = CourierShiftReport::where('employee_id', $employee->id)
            ->whereDate('date', $date)->where('shift_slot', $slot)->first();

        // Прийнятий звіт не перезаписуємо повторною розсилкою.
        if ($existing?->isAccepted()) {
            return $existing;
        }

        $expected = $this->expected($employee, $date, $slot);
        $text     = $this->renderTemplate($employee, $date, $slot, $expected);

        $messageId = $this->telegram->sendMessage(
            $employee->telegram_chat_id,
            "Звіт зміни — скопіюйте шаблон дотиком, допишіть порожнє і надішліть назад разом із двома фото одометра.\n\n<pre>".e($text).'</pre>',
        );

        $attrs = [
            'delivery_route_id'   => $expected['route_ids'][0] ?? null,
            'tg_chat_id'          => $employee->telegram_chat_id,
            'template_message_id' => $messageId,
            'template_sent_at'    => now(),
            'expected'            => $expected,
            'status'              => $existing?->status ?? CourierShiftReport::STATUS_SENT,
        ];

        return $existing
            ? tap($existing)->update($attrs)
            : CourierShiftReport::create($attrs + ['employee_id' => $employee->id, 'date' => $date, 'shift_slot' => $slot]);
    }

    /**
     * Нагадати наприкінці зміни тим, хто ще не здав звіт.
     */
    public function remind(string $date, string $slot): int
    {
        $reports = CourierShiftReport::with('employee')
            ->whereDate('date', $date)
            ->where('shift_slot', $slot)
            ->whereIn('status', [CourierShiftReport::STATUS_SENT, CourierShiftReport::STATUS_INCOMPLETE])
            ->get();

        foreach ($reports as $report) {
            $missing = $report->problems ?: ['пробіг фініш', 'фото одометра'];

            $this->telegram->sendMessage(
                (string) $report->tg_chat_id,
                '⏰ Нагадування: звіт зміни ще не здано. Бракує: '.implode(', ', $missing).'.',
            );

            $report->update(['reminded_at' => now()]);
        }

        return $reports->count();
    }

    /**
     * Усе, що система знає заздалегідь: маршрути, пробіг на старті, ціна
     * пального і хто з клієнтів платить готівкою.
     *
     * @return array<string, mixed>
     */
    public function expected(Employee $employee, string $date, string $slot): array
    {
        $routes = DeliveryRoute::where('employee_id', $employee->id)
            ->whereDate('date', $date)
            ->orderBy('ant_route_num')
            ->get()
            ->filter(fn (DeliveryRoute $r) => in_array($r->realShift(), [$slot, null], true))
            ->values();

        // Пробіг на старті — фініш попередньої зміни. Для вечірнього звіту це
        // може бути ранок того самого дня.
        $lastLog = CourierMileageLog::where('employee_id', $employee->id)
            ->whereNotNull('end_km')
            ->where(function ($q) use ($date, $slot) {
                $q->whereDate('date', '<', $date);

                if ($slot === CourierMileageLog::SLOT_EVENING) {
                    $q->orWhere(fn ($same) => $same->whereDate('date', $date)
                        ->where('shift_slot', CourierMileageLog::SLOT_MORNING));
                }
            })
            ->orderByDesc('date')->orderByDesc('id')
            ->first();

        $fuelPrice = CourierMileageLog::where('employee_id', $employee->id)
            ->where('fuel_price_per_liter', '>', 0)->latest('id')->value('fuel_price_per_liter')
            ?? CourierMileageLog::where('fuel_price_per_liter', '>', 0)->latest('id')->value('fuel_price_per_liter');

        return [
            'route_ids'   => $routes->pluck('id')->all(),
            'route_nums'  => $routes->pluck('ant_route_num')->filter()->all(),
            'stops'       => (int) $routes->sum('count_comps'),
            'distance'    => (float) $routes->sum('distance_calc'),
            'start_km'    => $lastLog?->end_km,
            'fuel_price'  => $fuelPrice !== null ? (float) $fuelPrice : null,
            'cash'        => $this->expectedCash($employee, $date, $slot)->all(),
        ];
    }

    /**
     * Неоплачені замовлення маршруту, за які курʼєр має взяти готівку: у
     * замовленні спосіб оплати «готівка» або клієнт уже сказав агенту, що
     * передасть готівкою.
     */
    private function expectedCash(Employee $employee, string $date, string $slot): Collection
    {
        $stops = RouteStop::forDelivery($date, $slot)
            ->where('employee_id', $employee->id)
            ->whereNotNull('order_id')
            ->orderBy('position')
            ->get()
            ->unique('order_id');

        $orders = Order::whereIn('id', $stops->pluck('order_id'))->get()->keyBy('id');

        return $stops
            ->map(function (RouteStop $stop) use ($orders) {
                $order = $orders[$stop->order_id] ?? null;

                if (! $order || $order->is_paid) {
                    return null;
                }

                $claim = PaymentClaim::pending()
                    ->where('order_id', $order->id)
                    ->where('source', PaymentClaim::SOURCE_CLIENT_CASH)
                    ->latest('id')->first();

                if ($order->payment_method !== 'cash' && ! $claim) {
                    return null;
                }

                $expected = $claim
                    ? (float) $claim->amount
                    : $this->claims->orderPaymentState($order)['debt'];

                return [
                    'order_id'      => $order->id,
                    'order_day_id'  => $stop->order_day_id,
                    'route_stop_id' => $stop->id,
                    'client'        => $this->shortName((string) $stop->client_name),
                    'area'          => $this->shortAddress((string) $stop->address),
                    'expected'      => round($expected, 2),
                ];
            })
            ->filter()
            ->values();
    }

    public function renderTemplate(Employee $employee, string $date, string $slot, array $expected): string
    {
        $shift  = $slot === CourierMileageLog::SLOT_EVENING ? 'вечір' : 'ранок';
        $routes = $expected['route_nums'] ? 'маршрут №'.implode(', №', $expected['route_nums']) : 'маршрут';
        $num    = fn ($v) => $v === null ? self::BLANK : rtrim(rtrim(number_format((float) $v, 2, '.', ''), '0'), '.');

        $lines   = [];
        $lines[] = 'ЗВІТ ЗМІНИ · '.Carbon::parse($date)->format('d.m').' · '.$shift.' · '.$routes.' ('.$expected['stops'].' точок)';
        $lines[] = 'Пробіг старт: '.$num($expected['start_km']);
        $lines[] = 'Пробіг фініш: '.self::BLANK;
        $lines[] = 'Пальне, грн/л: '.$num($expected['fuel_price']);
        $lines[] = 'Готівка від клієнтів:';

        if (empty($expected['cash'])) {
            $lines[] = '- немає';
        }

        foreach ($expected['cash'] as $c) {
            $area    = $c['area'] ? ' ('.$c['area'].')' : '';
            $lines[] = '- #'.$c['order_id'].' '.$c['client'].$area.' чекаємо '.$num($c['expected']).' → отримав: '.self::BLANK;
        }

        $lines[] = 'Коментар: '.self::BLANK;
        $lines[] = '📷 Фото одометра: старт і фініш';

        return implode("\n", $lines);
    }

    // -------------------------------------------------------------------------
    // Прийом відповіді
    // -------------------------------------------------------------------------

    /**
     * Курʼєр щось надіслав: текст звіту, фото або все разом.
     *
     * @param  array<int, string>  $photoFileIds  file_id найбільшої версії кожного фото
     * @return string відповідь курʼєру одним рядком
     */
    public function ingest(Employee $employee, ?string $text, array $photoFileIds = []): string
    {
        $report = $this->openReport($employee, $text);

        if (! $report) {
            return 'Не бачу відкритого звіту зміни. Шаблон приходить на початку зміни — якщо його не було, напишіть менеджеру.';
        }

        $parsed = $report->parsed ?? [];

        if ($text !== null && trim($text) !== '') {
            $parsed = array_replace($parsed, array_filter(
                $this->parse($text, $report->expected ?? []),
                fn ($v) => $v !== null,
            ));
            $report->raw_text = trim(($report->raw_text ? $report->raw_text."\n\n" : '').$text);
        }

        $photos = $report->photos ?? [];
        foreach ($photoFileIds as $fileId) {
            $path = $this->telegram->downloadFile($fileId, "courier-reports/{$report->id}");
            $photos[] = $path ?? "tg:{$fileId}";
        }

        $report->parsed = $parsed;
        $report->photos = $photos;

        $problems = $this->validate($report, $parsed, $photos);
        $report->problems = $problems;

        if ($problems) {
            $report->status = CourierShiftReport::STATUS_INCOMPLETE;
            $report->save();

            return 'Майже готово. Допишіть: '.implode(', ', $problems).'.';
        }

        $report->save();

        return $this->accept($report->fresh());
    }

    /**
     * Толерантний розбір: порожнє поле готівки — «не брав», «+» — «взяв рівно
     * очікувану суму». Кома чи крапка в ціні, пробіли в пробігу — байдуже.
     *
     * @return array<string, mixed>
     */
    public function parse(string $text, array $expected = []): array
    {
        $result = ['start_km' => null, 'end_km' => null, 'fuel_price' => null, 'comment' => null, 'cash' => null];

        $expectedCash = collect($expected['cash'] ?? [])->keyBy('order_id');
        $cash         = [];

        foreach (preg_split('/\R/u', $text) as $line) {
            $line = trim($line);
            $low  = mb_strtolower($line);

            if (preg_match('/пробіг\s*старт\s*:?\s*(.*)$/iu', $line, $m)) {
                $result['start_km'] = $this->intOrNull($m[1]);
            } elseif (preg_match('/пробіг\s*фініш\s*:?\s*(.*)$/iu', $line, $m)) {
                $result['end_km'] = $this->intOrNull($m[1]);
            } elseif (preg_match('/пальне[^:]*:\s*(.*)$/iu', $line, $m)) {
                $result['fuel_price'] = $this->floatOrNull($m[1]);
            } elseif (preg_match('/коментар\s*:?\s*(.*)$/iu', $line, $m)) {
                $value = trim(str_replace('_', '', $m[1]));
                $result['comment'] = $value === '' ? null : $value;
            } elseif (preg_match('/#(\d+).*?отримав\s*:?\s*(.*)$/iu', $line, $m)) {
                $orderId  = (int) $m[1];
                $raw      = trim(str_replace('_', '', $m[2]));
                $expectedAmount = (float) ($expectedCash[$orderId]['expected'] ?? 0);

                $received = match (true) {
                    $raw === ''  => 0.0,
                    $raw === '+' => $expectedAmount,
                    default      => (float) ($this->floatOrNull($raw) ?? 0),
                };

                $cash[] = array_merge($expectedCash[$orderId] ?? ['order_id' => $orderId], [
                    'order_id' => $orderId,
                    'received' => round($received, 2),
                ]);
            } elseif (str_starts_with($low, 'звіт зміни')) {
                continue;
            }
        }

        $result['cash'] = $cash === [] ? null : $cash;

        return $result;
    }

    /**
     * @return array<int, string> що саме дописати; порожньо — усе гаразд
     */
    public function validate(CourierShiftReport $report, array $parsed, array $photos): array
    {
        $problems = [];
        $start    = $parsed['start_km'] ?? ($report->expected['start_km'] ?? null);
        $end      = $parsed['end_km'] ?? null;

        if ($start === null) {
            $problems[] = 'пробіг старт';
        }

        if ($end === null) {
            $problems[] = 'пробіг фініш';
        }

        if ($start !== null && $end !== null) {
            $delta = $end - $start;

            if ($delta <= 0) {
                $problems[] = 'пробіг фініш має бути більшим за старт';
            } else {
                $plan  = (float) ($report->expected['distance'] ?? 0);
                $limit = $plan > 0 ? max($plan * 3, $plan + 80) : self::MAX_KM_WITHOUT_PLAN;

                if ($delta > $limit) {
                    $problems[] = "перевірте пробіг — {$delta} км забагато для зміни (межа ".(int) $limit.' км)';
                }
            }
        }

        if (($parsed['fuel_price'] ?? ($report->expected['fuel_price'] ?? null)) === null) {
            $problems[] = 'ціна пального';
        }

        if (count($photos) < 2) {
            $problems[] = 'фото одометра ('.count($photos).' з 2)';
        }

        return $problems;
    }

    /**
     * Звіт повний — розносимо його по системі.
     */
    private function accept(CourierShiftReport $report): string
    {
        $employee = $report->employee;
        $date     = $report->dateString();
        $parsed   = $report->parsed ?? [];

        DB::transaction(function () use ($report, $employee, $date, $parsed) {
            $mileageNote = $this->writeMileage($report, $employee, $date, $parsed);
            $cash        = $this->fileCashClaims($report, $parsed);

            $parsed['cash']         = $cash;
            $parsed['mileage_note'] = $mileageNote;

            $report->update([
                'parsed'      => $parsed,
                'status'      => CourierShiftReport::STATUS_ACCEPTED,
                'accepted_at' => now(),
                'problems'    => [],
            ]);
        });

        $report = $report->fresh();

        // Виплату шлемо на погодження, лише коли здано всі зміни дня: інакше
        // власник погодив би ранок, не знаючи про вечір.
        $payout = $this->payouts->refresh($employee, $date);

        if ($this->allShiftsReported($employee, $date)) {
            $this->payouts->sendForApproval($payout);
        }

        $km   = (int) (($parsed['end_km'] ?? 0) - ($parsed['start_km'] ?? $report->expected['start_km'] ?? 0));
        $cash = collect($report->parsed['cash'] ?? [])->sum('received');

        $reply = "Прийнято ✅ Пробіг {$km} км";
        if ($cash > 0) {
            $reply .= ', готівка '.number_format($cash, 0, ',', ' ').' ₴ — здайте менеджеру';
        }
        if ($report->parsed['mileage_note'] ?? null) {
            $reply .= '. '.$report->parsed['mileage_note'];
        }

        return $reply.'.';
    }

    /**
     * Пробіг — через той самий сервіс, що й сторінка Логістики: баланс рухається
     * на різницю компенсації. Якщо менеджер уже вніс пробіг цього дня в іншому
     * розрізі (увесь день проти ранку/вечора), не записуємо поверх — інакше
     * компенсацію порахувало б двічі.
     */
    private function writeMileage(CourierShiftReport $report, Employee $employee, string $date, array $parsed): ?string
    {
        $bothShifts = CourierShiftReport::where('employee_id', $employee->id)
            ->whereDate('date', $date)->count() > 1
            || DeliveryRoute::where('employee_id', $employee->id)->whereDate('date', $date)->get()
                ->map(fn ($r) => $r->realShift())->filter()->unique()->count() > 1;

        $slot     = $bothShifts ? $report->shift_slot : CourierMileageLog::SLOT_FULL;
        $conflict = $bothShifts
            ? [CourierMileageLog::SLOT_FULL]
            : [CourierMileageLog::SLOT_MORNING, CourierMileageLog::SLOT_EVENING];

        $clash = CourierMileageLog::where('employee_id', $employee->id)
            ->whereDate('date', $date)
            ->whereIn('shift_slot', $conflict)
            ->where(fn ($q) => $q->whereNotNull('end_km')->orWhereNotNull('start_km'))
            ->exists();

        if ($clash) {
            return 'Пробіг за цей день уже вніс менеджер — звіряться вручну';
        }

        $this->mileage->set($employee, $date, $slot, [
            'start_km'             => $parsed['start_km'] ?? ($report->expected['start_km'] ?? null),
            'end_km'               => $parsed['end_km'],
            'fuel_price_per_liter' => $parsed['fuel_price'] ?? ($report->expected['fuel_price'] ?? null),
        ]);

        return null;
    }

    /**
     * Рядки «отримав» → заяви courier_cash. Вони зводяться в пару з заявою
     * клієнта і потрапляють у «Чекають підтвердження» — звірка по клієнтах і по
     * курʼєру в одному місці.
     *
     * Курʼєр може надіслати виправлений звіт — тоді заяву оновлюємо, а не плодимо.
     */
    private function fileCashClaims(CourierShiftReport $report, array $parsed): array
    {
        $lines = [];

        foreach ($parsed['cash'] ?? [] as $line) {
            $orderId = (int) ($line['order_id'] ?? 0);
            $amount  = (float) ($line['received'] ?? 0);
            $order   = $orderId ? Order::find($orderId) : null;

            if (! $order) {
                continue;
            }

            $existing = PaymentClaim::where('order_id', $orderId)
                ->where('source', PaymentClaim::SOURCE_COURIER_CASH)
                ->where('employee_id', $report->employee_id)
                ->where('comment', 'like', "%звіт #{$report->id}%")
                ->first();

            if ($existing) {
                if ($existing->isPending() && $amount > 0) {
                    $existing->update(['amount' => $amount]);
                } elseif ($existing->isPending()) {
                    $existing->update(['status' => PaymentClaim::STATUS_SUPERSEDED, 'comment' => $existing->comment.' · курʼєр прибрав']);
                }
                $line['claim_id'] = $existing->id;
            } elseif ($amount > 0) {
                $claim = $this->claims->create($order, [
                    'source'           => PaymentClaim::SOURCE_COURIER_CASH,
                    'amount'           => $amount,
                    'reported_by_type' => PaymentClaim::REPORTED_BY_COURIER,
                    'reported_by_id'   => $report->employee_id,
                    'employee_id'      => $report->employee_id,
                    'order_day_id'     => $line['order_day_id'] ?? null,
                    'route_stop_id'    => $line['route_stop_id'] ?? null,
                    'comment'          => "Звіт зміни курʼєра (звіт #{$report->id})",
                ]);
                $line['claim_id'] = $claim->id;
            }

            $lines[] = $line;
        }

        return $lines;
    }

    // -------------------------------------------------------------------------

    private function allShiftsReported(Employee $employee, string $date): bool
    {
        $reports = CourierShiftReport::where('employee_id', $employee->id)->whereDate('date', $date)->get();

        return $reports->isNotEmpty() && $reports->every(fn (CourierShiftReport $r) => $r->isAccepted());
    }

    /**
     * Який звіт курʼєр заповнює. Скопійований шаблон несе дату й зміну в
     * заголовку — за ним і шукаємо. Фото без підпису чіпляємо до останнього
     * відкритого звіту.
     */
    private function openReport(Employee $employee, ?string $text): ?CourierShiftReport
    {
        $query = CourierShiftReport::where('employee_id', $employee->id)
            ->whereIn('status', [CourierShiftReport::STATUS_SENT, CourierShiftReport::STATUS_INCOMPLETE, CourierShiftReport::STATUS_ACCEPTED])
            ->whereDate('date', '>=', now()->subDays(2)->toDateString());

        if ($text && preg_match('/звіт зміни\s*·\s*(\d{2})\.(\d{2})\s*·\s*(ранок|вечір)/iu', $text, $m)) {
            $date = Carbon::create((int) now()->format('Y'), (int) $m[2], (int) $m[1])->toDateString();
            $slot = mb_strtolower($m[3]) === 'вечір' ? CourierMileageLog::SLOT_EVENING : CourierMileageLog::SLOT_MORNING;

            $found = (clone $query)->whereDate('date', $date)->where('shift_slot', $slot)->first();

            if ($found) {
                return $found;
            }
        }

        return (clone $query)
            ->whereIn('status', [CourierShiftReport::STATUS_SENT, CourierShiftReport::STATUS_INCOMPLETE])
            ->orderByDesc('date')->orderByDesc('template_sent_at')
            ->first();
    }

    /** @return Collection<int, Employee> */
    private function couriersOnShift(string $date, string $slot): Collection
    {
        $ids = DeliveryRoute::whereDate('date', $date)
            ->whereNotNull('employee_id')
            ->get()
            ->filter(fn (DeliveryRoute $r) => $r->realShift() === $slot)
            ->pluck('employee_id')
            ->unique();

        return Employee::whereIn('id', $ids)->whereNotNull('telegram_chat_id')->get();
    }

    private function shortName(string $name): string
    {
        $parts = preg_split('/\s+/u', trim($name)) ?: [];

        if (count($parts) < 2) {
            return trim($name);
        }

        return $parts[0].' '.mb_substr($parts[1], 0, 1).'.';
    }

    private function shortAddress(string $address): string
    {
        $first = trim((string) (explode(',', $address)[0] ?? ''));

        return mb_strlen($first) > 28 ? mb_substr($first, 0, 27).'…' : $first;
    }

    private function intOrNull(string $raw): ?int
    {
        $digits = preg_replace('/\D/u', '', $raw);

        return $digits === '' ? null : (int) $digits;
    }

    private function floatOrNull(string $raw): ?float
    {
        $clean = str_replace([' ', ','], ['', '.'], trim(str_replace('_', '', $raw)));

        return preg_match('/^\d+(\.\d+)?/', $clean, $m) ? (float) $m[0] : null;
    }
}
