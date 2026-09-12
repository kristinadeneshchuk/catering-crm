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
