<?php

namespace App\Services\Couriers;

use App\Filament\Resources\CourierPayoutResource;
use App\Models\CourierPayout;
use App\Models\Employee;
use App\Services\TelegramService;
use Illuminate\Support\Facades\DB;

/**
 * Виплата курʼєру: знімок розрахунку → повідомлення власнику → погодження.
 *
 * employees.balance тут не рухається: гроші вже нараховані чинною логікою.
 * Погоджена виплата лише зʼявляється в «Зарплатах», а платять, як і раніше,
 * вручну.
 */
class CourierPayoutService
{
    public const ACTION_APPROVE = 'approve';
    public const ACTION_REJECT  = 'reject';

    public function __construct(
        private CourierPayoutCalculator $calculator,
        private TelegramService $telegram,
    ) {
    }

    /**
     * Перерахувати знімок за день. Погоджену чи виплачену не чіпаємо — це вже
     * рішення, і цифри в ньому не мають пливти від пізніших правок маршруту.
     */
    public function refresh(Employee $employee, string $date): CourierPayout
    {
        $existing = CourierPayout::where('employee_id', $employee->id)->whereDate('date', $date)->first();

        if ($existing?->isLocked()) {
            return $existing;
        }

        $calc = $this->calculator->calculate($employee, $date);

        $attrs = [
            'shift_slot'        => $calc['shift_slot'],
            'delivery_route_id' => $calc['delivery_route_id'],
            'mileage_log_id'    => $calc['mileage_log_id'],
            'components'        => $calc['components'],
            'total'             => $calc['total'],
            'cash_on_hand'      => $calc['cash_on_hand'],
            'to_pay'            => $calc['to_pay'],
        ];

        if ($existing) {
            $existing->update($attrs);

            return $existing->fresh();
        }

        return CourierPayout::create($attrs + [
            'employee_id' => $employee->id,
            'date'        => $date,
            'status'      => CourierPayout::STATUS_DRAFT,
        ]);
    }

    /**
     * Надіслати на погодження власнику і старшому менеджеру. Якщо вже
     * надсилали — оновлюємо ті самі повідомлення, а не шлемо нові.
     */
    public function sendForApproval(CourierPayout $payout): void
    {
        if ($payout->isLocked()) {
            return;
        }

        $text     = $this->render($payout);
        $keyboard = $this->keyboard($payout);
        $messages = $payout->tg_messages ?? [];

        if ($messages !== []) {
            foreach ($messages as $m) {
                $this->telegram->editMessage((string) $m['chat_id'], (int) $m['message_id'], $text, $keyboard);
            }
        } else {
            foreach ($this->telegram->approverChatIds() as $chatId) {
                $id = $this->telegram->sendMessage($chatId, $text, $keyboard);

                if ($id) {
                    $messages[] = ['chat_id' => $chatId, 'message_id' => $id];
                }
            }
        }

        $payout->update([
            'status'        => CourierPayout::STATUS_SENT,
            'tg_messages'   => $messages,
            'reject_reason' => null,
        ]);
    }

    /**
     * Натиснули кнопку в Telegram. Віримо лише власнику і старшому менеджеру:
     * будь-хто інший, хто випадково опинився в чаті, погодити не зможе.
     *
     * @return string відповідь, яку побачить той, хто натиснув
     */
    public function handleButton(string $fromId, string $action, int $payoutId): string
    {
        if (! in_array($fromId, $this->telegram->approverChatIds(), true)) {
            return 'Погоджувати можуть лише власник і старший менеджер.';
        }

        return DB::transaction(function () use ($fromId, $action, $payoutId) {
            $payout = CourierPayout::lockForUpdate()->find($payoutId);

            if (! $payout) {
                return 'Виплату не знайдено.';
            }

            // Дві людини отримали те саме повідомлення — і могли натиснути
            // одночасно. Друга побачить, що рішення вже ухвалене.
            if ($payout->status !== CourierPayout::STATUS_SENT) {
                return 'Уже вирішено: '.(CourierPayout::statusLabels()[$payout->status] ?? $payout->status).'.';
            }

            if ($action === self::ACTION_APPROVE) {
                $payout->update([
                    'status'         => CourierPayout::STATUS_APPROVED,
                    'approved_by_tg' => $fromId,
                    'approved_at'    => now(),
                ]);
                $answer = 'Погоджено ✅';
            } elseif ($action === self::ACTION_REJECT) {
                $payout->update([
                    'status'         => CourierPayout::STATUS_REJECTED,
                    'approved_by_tg' => $fromId,
                    'approved_at'    => now(),
                    'reject_reason'  => 'Відхилено в Telegram',
                ]);
                $answer = 'Відхилено ❌';
            } else {
                return 'Невідома дія.';
            }

            $this->updateMessages($payout->fresh());

            return $answer;
        });
    }

    /** Після правки в CRM або рішення — оновити повідомлення в обох чатах. */
    public function updateMessages(CourierPayout $payout): void
    {
        $keyboard = $payout->status === CourierPayout::STATUS_SENT ? $this->keyboard($payout) : null;

        foreach ($payout->tg_messages ?? [] as $m) {
            $this->telegram->editMessage((string) $m['chat_id'], (int) $m['message_id'], $this->render($payout), $keyboard);
        }
    }

    /** Виплатили в «Зарплатах» — погоджені дні періоду стають виплаченими. */
    public function markPaid(Employee $employee, string $from, string $to): int
    {
        return CourierPayout::where('employee_id', $employee->id)
            ->where('status', CourierPayout::STATUS_APPROVED)
            ->whereBetween('date', [$from, $to])
            ->update(['status' => CourierPayout::STATUS_PAID, 'paid_at' => now()]);
    }

    // -------------------------------------------------------------------------
    // Погодження в CRM з вибором рахунку → чат оплат
    // -------------------------------------------------------------------------

    /**
     * «ЗП погоджена»: адмін у CRM обирає, з якого рахунку платимо (ФОП чи
     * готівка). Виплата проводиться тією самою транзакцією «Виплата ЗП», що й
     * кнопка «Виплатити» в «Зарплатах», а в чат оплат іде розклад дня з
     * карткою курʼєра.
     *
     * @return array{ok: bool, error?: string, sent?: bool}
     */
    public function approveAndPay(CourierPayout $payout, int $accountId, ?float $amount, ?int $userId, ?string $comment = null): array
    {
        if (($blocker = $this->paymentBlocker($payout)) !== null) {
            return ['ok' => false, 'error' => $blocker];
        }

        $account = \App\Models\Account::find($accountId);

        if (! $account) {
            return ['ok' => false, 'error' => 'Оберіть рахунок, з якого платимо.'];
        }

        // Свіжий знімок: після перевірки звіту цифри могли змінитись.
        $payout = $this->refresh($payout->employee, $payout->dateString());
        $amount = round((float) ($amount ?? max(0, (float) $payout->to_pay)), 2);

        $this->applyPayment($payout, $account, $amount, $userId, $comment);

        return ['ok' => true, 'sent' => $this->sendPaymentMessage(collect([$payout->fresh()]))];
    }

    /**
     * Погодити кілька днів одного курʼєра — одна транзакція на день і **одне**
     * повідомлення в чат оплат з розкладом по днях (як платять зараз руками).
     *
     * @param  \Illuminate\Support\Collection<int, CourierPayout>  $payouts
     * @return array{ok: bool, paid: int, errors: array<int, string>, sent: bool}
     */
    public function approveAndPayMany($payouts, int $accountId, ?int $userId, ?string $comment = null): array
    {
        $account = \App\Models\Account::find($accountId);
        $paid    = collect();
        $errors  = [];

        if (! $account) {
            return ['ok' => false, 'paid' => 0, 'errors' => ['Оберіть рахунок, з якого платимо.'], 'sent' => false];
        }

        foreach (collect($payouts)->sortBy(fn (CourierPayout $p) => $p->dateString()) as $payout) {
            $check = $this->paymentBlocker($payout);

            if ($check !== null) {
                $errors[] = $payout->employee?->name.' '.$payout->dateString().': '.$check;

                continue;
            }

            $fresh = $this->refresh($payout->employee, $payout->dateString());
            $this->applyPayment($fresh, $account, max(0, (float) $fresh->to_pay), $userId, $comment);
            $paid->push($fresh->fresh());
        }

        $sent = $paid->isNotEmpty() && $this->sendPaymentMessage($paid);

        return ['ok' => $paid->isNotEmpty(), 'paid' => $paid->count(), 'errors' => $errors, 'sent' => $sent];
    }

    /** Чому цей день ще не можна виплатити; null — можна. */
    public function paymentBlocker(CourierPayout $payout): ?string
    {
        if ($payout->status === CourierPayout::STATUS_PAID) {
            return 'Цей день уже виплачено.';
        }

        $drafts = \App\Models\CourierShiftReport::where('employee_id', $payout->employee_id)
            ->whereDate('date', $payout->dateString())
            ->where('status', \App\Models\CourierShiftReport::STATUS_DRAFT)
            ->count();

        return $drafts > 0
            ? 'Спершу перевірте звіт курʼєра за цей день (Логістика → Звіти курʼєрів).'
            : null;
    }

    private function applyPayment(CourierPayout $payout, \App\Models\Account $account, float $amount, ?int $userId, ?string $comment): void
    {
        $employee = $payout->employee;
        $date     = $payout->dateString();

        DB::transaction(function () use ($payout, $employee, $account, $amount, $userId, $comment, $date) {
            $transactionId = null;

            if ($amount > 0) {
                // Баланс курʼєра зменшує хук Transaction::created — як у «Зарплатах».
                $transactionId = \App\Models\Transaction::create([
                    'employee_id' => $employee->id,
                    'order_id'    => null,
                    'account_id'  => $account->id,
                    'amount'      => $amount,
                    'type'        => 'expense',
                    'category'    => 'Виплата ЗП',
                    'date'        => now()->toDateString(),
                    'comment'     => $comment ?: 'ЗП курʼєра за '.\Carbon\Carbon::parse($date)->format('d.m').": {$employee->name}",
                    'user_id'     => $userId,
                ])->id;
            }

            $payout->update([
                'status'         => CourierPayout::STATUS_PAID,
                'approved_by'    => $userId,
                'approved_at'    => now(),
                'paid_at'        => now(),
                'account_id'     => $account->id,
                'transaction_id' => $transactionId,
                'paid_amount'    => $amount,
                'comment'        => $comment ?: $payout->comment,
            ]);
        });
    }

    /**
     * Помилково погодили — скасувати: транзакцію видаляємо (хук поверне борг
     * курʼєру), день знову чекає погодження, у чаті оплат — позначка.
     */
    public function cancelPayment(CourierPayout $payout): bool
    {
        if ($payout->status !== CourierPayout::STATUS_PAID) {
            return false;
        }

        // Текст беремо до скидання полів: у ньому ще видно, звідки платили.
        // У БД текст не зберігаємо — у ньому повний номер картки.
        $text = $this->renderPaymentMessage(collect([$payout]));

        DB::transaction(function () use ($payout) {
            if ($payout->transaction_id) {
                \App\Models\Transaction::find($payout->transaction_id)?->delete();
            }

            $payout->update([
                'status'         => CourierPayout::STATUS_DRAFT,
                'approved_by'    => null,
                'approved_at'    => null,
                'paid_at'        => null,
                'account_id'     => null,
                'transaction_id' => null,
                'paid_amount'    => null,
            ]);
        });

        $message = $payout->fresh()->payment_message;

        if (! empty($message['chat_id']) && ! empty($message['message_id'])) {
            $this->telegram->editMessage(
                (string) $message['chat_id'],
                (int) $message['message_id'],
                $text."\n\n❌ <b>Скасовано</b> ".now()->format('d.m H:i').' — не платити.',
            );
        }

        return true;
    }

    /**
     * Порахувати виплати всім курʼєрам, що працювали в цей день. Потрібно, коли
     * звіту через бота не було: пробіг вніс менеджер у Логістиці.
     */
    public function refreshDay(string $date): int
    {
        $ids = \App\Models\EmployeeShift::whereDate('date', $date)
            ->where('is_planned', false)
            ->pluck('employee_id')
            ->merge(\App\Models\DeliveryRoute::whereDate('date', $date)->whereNotNull('employee_id')->pluck('employee_id'))
            ->unique();

        $n = 0;

        foreach (Employee::whereIn('id', $ids)->where('position', 'courier')->get() as $employee) {
            $this->refresh($employee, $date);
            $n++;
        }

        return $n;
    }

    /** @param  \Illuminate\Support\Collection<int, CourierPayout>  $payouts  дні одного курʼєра */
    public function sendPaymentMessage($payouts): bool
    {
        $payouts = collect($payouts);
        $chatId  = (string) config('ops.payments_chat_id');

        if ($chatId === '' || $payouts->isEmpty()) {
            return false;
        }

        $id = $this->telegram->sendMessage($chatId, $this->renderPaymentMessage($payouts));

        if ($id) {
            foreach ($payouts as $payout) {
                $payout->update(['payment_message' => ['chat_id' => $chatId, 'message_id' => $id]]);
            }
        }

        return (bool) $id;
    }

    /**
     * Повідомлення в чат оплат — у тому ж вигляді, у якому виплати пишуть руками:
     * імʼя, картка, рядки по днях (ЗП окремо, пальне з амортизацією окремо) і
     * підсумок «ДО ВИПЛАТИ».
     *
     * @param  \Illuminate\Support\Collection<int, CourierPayout>  $payouts  дні одного курʼєра
     */
    public function renderPaymentMessage($payouts): string
    {
        $payouts  = collect($payouts)->sortBy(fn (CourierPayout $p) => $p->dateString())->values();
        $employee = $payouts->first()?->employee;
        $money    = fn ($v) => number_format((float) $v, 0, ',', ' ').' грн';
        $e        = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES);
        $weekdays = ['нд', 'пн', 'вт', 'ср', 'чт', 'пт', 'сб'];

        $lines   = [];
        $lines[] = '<b>'.$e($employee?->name).'</b> (курʼєр)';
        $card    = $employee?->formattedPayoutCard();
        $lines[] = $card ? '<code>'.$card.'</code>' : '⚠️ картку не внесено в CRM';

        $marks = collect();

        foreach ($payouts as $payout) {
            $c    = $payout->components ?? [];
            $date = \Carbon\Carbon::parse($payout->dateString());
            $day  = $weekdays[$date->dayOfWeek].' '.$date->format('d.m.y');

            $lines[] = '';
            $lines[] = 'ЗП + компенсація пальне + амортизація за '.$date->format('d.m.y');

            $trips   = (int) ($c['trips'] ?? 0);
            $stops   = collect($c['routes'] ?? [])->sum('stops');
            $zpNote  = $trips ? $trips.($trips === 1 ? ' виїзд' : ' виїзди') : 'ставка';
            $zpNote .= $stops ? ', '.$stops.' точок' : '';
            $lines[] = 'за '.$day.' — '.$money($c['booked_rate'] ?? 0).' (ЗП: '.$zpNote.')';

            $km    = collect($c['mileage'] ?? [])->sum('km');
            $fuel  = collect($c['mileage'] ?? [])->sum('fuel_cost');
            $amort = collect($c['mileage'] ?? [])->sum('amortization');

            if ($fuel + $amort > 0) {
                $lines[] = 'за '.$day.' — '.$money($fuel + $amort).' ('.(int) $km.' км: пальне '
                    .$money($fuel).' + амортизація '.$money($amort).')';
            }

            foreach ($c['bonuses'] ?? [] as $b) {
                $lines[] = 'за '.$day.' — +'.$money($b['amount']).' (бонус'.($b['reason'] ? ': '.$e($b['reason']) : '').')';
            }

            foreach ($c['penalties'] ?? [] as $pen) {
                $lines[] = 'за '.$day.' — −'.$money($pen['amount']).' (штраф'.($pen['reason'] ? ': '.$e($pen['reason']) : '').')';
            }

            if ((float) $payout->cash_on_hand > 0) {
                $lines[] = 'готівка від клієнтів на руках — −'.$money($payout->cash_on_hand);
            }

            $lines[] = 'До виплати: <b>'.$money($payout->paid_amount ?? $payout->to_pay).'</b>';

            $marks = $marks->merge(
                \App\Models\CourierShiftReport::where('employee_id', $payout->employee_id)
                    ->whereDate('date', $payout->dateString())
                    ->where('status', \App\Models\CourierShiftReport::STATUS_ACCEPTED)
                    ->get()
                    ->flatMap(fn ($r) => collect($r->anomalies ?? [])->where('severity', '!=', 'info'))
                    ->pluck('text'),
            );
        }

        $total   = $payouts->sum(fn (CourierPayout $p) => (float) ($p->paid_amount ?? $p->to_pay));
        $account = $payouts->first()?->account?->name;

        $lines[] = '';
        $lines[] = '<b>ДО ВИПЛАТИ: '.$money($total).'</b> ✅'.($account ? ' · з рахунку «'.$e($account).'»' : '');

        foreach ($marks->unique() as $mark) {
            $lines[] = '⚠️ '.$e($mark).' <i>Перевірено.</i>';
        }

        $comment = $payouts->pluck('comment')->filter()->unique()->implode(' · ');

        if ($comment !== '') {
            $lines[] = '💬 '.$e($comment);
        }

        return implode("\n", $lines);
    }

    // -------------------------------------------------------------------------
    // Повідомлення
    // -------------------------------------------------------------------------

    /** @return array<int, array<int, array<string, string>>> */
    public function keyboard(CourierPayout $payout): array
    {
        return [[
            ['text' => '✅ Погодити', 'callback_data' => "payout:".self::ACTION_APPROVE.":{$payout->id}"],
            ['text' => '✏️ Виправити', 'url' => $this->editUrl($payout)],
            ['text' => '❌ Відхилити', 'callback_data' => "payout:".self::ACTION_REJECT.":{$payout->id}"],
        ]];
    }

    public function editUrl(CourierPayout $payout): string
    {
        return CourierPayoutResource::getUrl('edit', ['record' => $payout]);
    }

    public function render(CourierPayout $payout): string
    {
        $c     = $payout->components ?? [];
        $money = fn ($v) => number_format((float) $v, 0, ',', ' ').' ₴';
        $e     = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES);
        $date  = \Carbon\Carbon::parse($payout->dateString())->format('d.m');

        $lines   = [];
        $lines[] = '<b>Виплата курʼєру · '.$e($c['employee'] ?? $payout->employee?->name).' · '.$date.'</b>';
        $lines[] = '';

        $trips = (int) ($c['trips'] ?? 0);
        $lines[] = 'Ставка: '.$money($c['base_rate'] ?? 0).' × '.$trips.' '.($trips === 1 ? 'виїзд' : 'виїзди').' = '.$money($c['base'] ?? 0);

        foreach ($c['routes'] ?? [] as $r) {
            $label = 'Маршрут №'.$e($r['num']).' · '.(int) $r['stops'].' точ.';
            $parts = [];

            if (($r['extra_stops_amount'] ?? 0) > 0) {
                $parts[] = 'понад ліміт +'.(int) $r['extra_stops'].' = '.$money($r['extra_stops_amount']);
            }
            if (($r['far_amount'] ?? 0) > 0) {
                $parts[] = 'дальні '.$money($r['far_amount']);
            }

            $lines[] = $label.($parts ? ': '.implode(', ', $parts) : '');
        }

        if (abs((float) ($c['adjustment'] ?? 0)) >= 0.01) {
            $sign    = $c['adjustment'] > 0 ? '+' : '−';
            $lines[] = 'Коригування в Табелі: '.$sign.$money(abs($c['adjustment']));
        }

        foreach ($c['mileage'] ?? [] as $m) {
            if (($m['compensation'] ?? 0) <= 0) {
                continue;
            }

            $lines[] = 'Пальне: '.(int) $m['km'].' км × '.rtrim(rtrim(number_format($m['consumption'], 1, '.', ''), '0'), '.')
                .' л/100 × '.number_format($m['fuel_price'], 2, '.', '').' = '.$money($m['fuel_cost']);
            $lines[] = 'Амортизація: '.(int) $m['km'].' км × '.number_format($m['amort_per_km'], 2, '.', '').' = '.$money($m['amortization']);
        }

        foreach ($c['bonuses'] ?? [] as $b) {
            $lines[] = 'Бонус: +'.$money($b['amount']).($b['reason'] ? ' ('.$e($b['reason']).')' : '');
        }

        foreach ($c['penalties'] ?? [] as $p) {
            $lines[] = 'Штраф: −'.$money($p['amount']).($p['reason'] ? ' ('.$e($p['reason']).')' : '');
        }

        $lines[] = '';
        $lines[] = 'Разом нараховано: <b>'.$money($payout->total).'</b>';

        if ((float) $payout->cash_on_hand > 0) {
            $lines[] = 'Готівка на руках: −'.$money($payout->cash_on_hand);
        }

        $lines[] = '<b>До виплати: '.$money($payout->to_pay).'</b>';

        if ($payout->comment) {
            $lines[] = '';
            $lines[] = '💬 '.$e($payout->comment);
        }

        if ($payout->status !== CourierPayout::STATUS_SENT && $payout->status !== CourierPayout::STATUS_DRAFT) {
            $lines[] = '';
            $lines[] = '— '.(CourierPayout::statusLabels()[$payout->status] ?? $payout->status)
                .($payout->approved_at ? ' · '.$payout->approved_at->format('d.m H:i') : '');
        }

        return implode("\n", $lines);
    }
}
