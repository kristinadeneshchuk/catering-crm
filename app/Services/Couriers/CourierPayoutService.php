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
        if ($payout->status === CourierPayout::STATUS_PAID) {
            return ['ok' => false, 'error' => 'Цей день уже виплачено.'];
        }

        $employee = $payout->employee;
        $date     = $payout->dateString();

        $drafts = \App\Models\CourierShiftReport::where('employee_id', $employee->id)
            ->whereDate('date', $date)
            ->where('status', \App\Models\CourierShiftReport::STATUS_DRAFT)
            ->count();

        if ($drafts > 0) {
            return ['ok' => false, 'error' => 'Спершу перевірте звіт курʼєра за цей день (Логістика → Звіти курʼєрів).'];
        }

        $account = \App\Models\Account::find($accountId);

        if (! $account) {
            return ['ok' => false, 'error' => 'Оберіть рахунок, з якого платимо.'];
        }

        // Свіжий знімок: після перевірки звіту цифри могли змінитись.
        $payout = $this->refresh($employee, $date);
        $amount = round((float) ($amount ?? max(0, (float) $payout->to_pay)), 2);

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

        return ['ok' => true, 'sent' => $this->sendPaymentMessage($payout->fresh())];
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
        $text = $this->renderPaymentMessage($payout);

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

    public function sendPaymentMessage(CourierPayout $payout): bool
    {
        $chatId = (string) config('ops.payments_chat_id');

        if ($chatId === '') {
            return false;
        }

        $text = $this->renderPaymentMessage($payout);
        $id   = $this->telegram->sendMessage($chatId, $text);

        if ($id) {
            $payout->update(['payment_message' => ['chat_id' => $chatId, 'message_id' => $id]]);
        }

        return (bool) $id;
    }

    public function renderPaymentMessage(CourierPayout $payout): string
    {
        $c        = $payout->components ?? [];
        $employee = $payout->employee;
        $money    = fn ($v) => number_format((float) $v, 0, ',', ' ').' ₴';
        $e        = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES);
        $date     = \Carbon\Carbon::parse($payout->dateString());
        $weekday  = ['нд', 'пн', 'вт', 'ср', 'чт', 'пт', 'сб'][$date->dayOfWeek];
        $slot     = fn ($s) => match ($s) { 'morning' => 'ранок', 'evening' => 'вечір', default => 'день' };

        $lines   = [];
        $lines[] = '💳 <b>ЗП курʼєр · '.$e($employee?->name).' · '.$weekday.' '.$date->format('d.m').'</b>';
        $card    = $employee?->formattedPayoutCard();
        $lines[] = $card ? 'Картка: <code>'.$card.'</code>' : '⚠️ Картку не внесено в CRM';
        $lines[] = '';

        foreach ($c['routes'] ?? [] as $r) {
            $lines[] = 'Маршрут №'.$e($r['num']).' · '.$slot($r['shift'] ?? null).' · '.(int) $r['stops'].' точ.';
        }

        $km = collect($c['mileage'] ?? [])->sum('km');
        if ($km > 0) {
            $lines[] = 'Пробіг: '.(int) $km.' км';
        }

        // ЗП = ставка з Табеля: виїзди + точки понад ліміт + дальні + ручне коригування.
        $parts = [];
        $trips = (int) ($c['trips'] ?? 0);
        $parts[] = 'ставка '.$trips.' × '.$money($c['base_rate'] ?? 0);
        foreach ($c['routes'] ?? [] as $r) {
            if (($r['extra_stops_amount'] ?? 0) > 0) {
                $parts[] = 'понад ліміт +'.(int) $r['extra_stops'].' = '.$money($r['extra_stops_amount']);
            }
            if (($r['far_amount'] ?? 0) > 0) {
                $parts[] = 'дальні '.$money($r['far_amount']);
            }
        }
        if (abs((float) ($c['adjustment'] ?? 0)) >= 0.01) {
            $parts[] = 'коригування '.($c['adjustment'] > 0 ? '+' : '−').$money(abs($c['adjustment']));
        }
        $lines[] = 'ЗП: <b>'.$money($c['booked_rate'] ?? 0).'</b> ('.implode('; ', $parts).')';

        foreach ($c['mileage'] ?? [] as $m) {
            if (($m['compensation'] ?? 0) <= 0) {
                continue;
            }
            $lines[] = 'Пальне: '.(int) $m['km'].' км × '.rtrim(rtrim(number_format($m['consumption'], 1, '.', ''), '0'), '.')
                .' л/100 × '.number_format($m['fuel_price'], 2, ',', '').' = <b>'.$money($m['fuel_cost']).'</b>';
            $lines[] = 'Амортизація: '.(int) $m['km'].' км × '.number_format($m['amort_per_km'], 2, ',', '').' = <b>'.$money($m['amortization']).'</b>';
        }

        foreach ($c['bonuses'] ?? [] as $b) {
            $lines[] = 'Бонус: +'.$money($b['amount']).($b['reason'] ? ' — '.$e($b['reason']) : '');
        }
        foreach ($c['penalties'] ?? [] as $p) {
            $lines[] = 'Штраф: −'.$money($p['amount']).($p['reason'] ? ' — '.$e($p['reason']) : '');
        }

        $lines[] = '';
        $lines[] = 'Заробив за день: <b>'.$money($payout->total).'</b>';

        if ((float) $payout->cash_on_hand > 0) {
            $lines[] = 'Готівка від клієнтів на руках: −'.$money($payout->cash_on_hand);
        }

        $account = $payout->account?->name;
        $paid    = $payout->paid_amount !== null ? (float) $payout->paid_amount : (float) $payout->to_pay;
        $lines[] = '<b>До виплати: '.$money($paid).'</b>'.($account ? ' · з рахунку «'.$e($account).'»' : '');

        if ((float) $payout->to_pay < 0) {
            $lines[] = 'Курʼєр винен компанії '.$money(abs((float) $payout->to_pay)).' — утримати з наступної виплати.';
        }

        // Позначки зі звітів дня, які адмін бачив і підтвердив.
        $marks = \App\Models\CourierShiftReport::where('employee_id', $payout->employee_id)
            ->whereDate('date', $payout->dateString())
            ->where('status', \App\Models\CourierShiftReport::STATUS_ACCEPTED)
            ->get()
            ->flatMap(fn ($r) => collect($r->anomalies ?? [])->where('severity', '!=', 'info'))
            ->pluck('text');

        foreach ($marks as $t) {
            $lines[] = '⚠️ '.$e($t).' <i>Перевірено.</i>';
        }

        if ($payout->comment) {
            $lines[] = '💬 '.$e($payout->comment);
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
