<?php

namespace App\Services\Logistics;

use App\Models\AntPush;
use App\Models\Order;
use App\Models\OrderDay;
use App\Services\AntLogisticsService;
use App\Services\TelegramService;
use App\Support\SchemaReady;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Замовлення після відправки зміни в ANT.
 *
 * Зміну відправляють у ANT раз на день, логіст будує маршрут і друкує лист.
 * Усе, що додали чи зняли в CRM пізніше, у маршрут саме не потрапляє: кухня
 * готує, а курʼєр про точку не знає. Тут — порівняння поточного стану зі
 * знімком відправки і попередження менеджерам.
 */
class LatePushWatch
{
    public const SHIFTS = ['morning' => 'ранок', 'evening' => 'вечір'];

    public function __construct(private AntLogisticsService $ant) {}

    /** Зберегти знімок: ці дні доставки щойно пішли в ANT. */
    public function record(string $deliveryDate, string $shift, Collection $days): void
    {
        if (! SchemaReady::has('ant_pushes')) {
            return;
        }

        $date = Carbon::parse($deliveryDate)->format('Y-m-d');
        $push = AntPush::whereDate('delivery_date', $date)->where('shift', $shift)->first()
            ?? new AntPush(['delivery_date' => $date, 'shift' => $shift]);

        $push->fill(
            [
                'pushed_at' => now(),
                'user_id'   => auth()->id(),
                'points'    => $days->map(fn (OrderDay $d) => [
                    'day_id'    => (int) $d->id,
                    'order_id'  => (int) $d->order_id,
                    'client_id' => (int) ($d->order?->client_id ?? 0),
                    'name'      => (string) ($d->order?->client?->name ?? ''),
                    'shift'     => $this->ant->orderDayIsEvening($d) ? 'evening' : 'morning',
                ])->values()->all(),
            ],
        )->save();
    }

    /** Остання відправка, що покриває цю зміну: саме її або «всі зміни». */
    public function pushFor(string $deliveryDate, string $shift): ?AntPush
    {
        if (! SchemaReady::has('ant_pushes')) {
            return null;
        }

        return AntPush::whereDate('delivery_date', $deliveryDate)
            ->whereIn('shift', [$shift, 'all'])
            ->orderByDesc('pushed_at')
            ->first();
    }

    /**
     * Що змінилось після відправки, по змінах. Порожній масив — усе збігається.
     *
     * @return array<string, array{label:string, pushed_at:string, added:array<int,array>, removed:array<int,array>}>
     */
    public function diff(string $deliveryDate): array
    {
        $out = [];

        foreach (self::SHIFTS as $shift => $label) {
            $push = $this->pushFor($deliveryDate, $shift);
            if (! $push) {
                continue;
            }

            $sent    = collect($push->points)->where('shift', $shift)->keyBy('day_id');
            $current = $this->ant->collectOrderDaysForDelivery($deliveryDate, $shift)['days']->keyBy('id');

            $added = $current->reject(fn ($d, $id) => $sent->has($id))
                ->groupBy(fn (OrderDay $d) => $d->order?->client_id)
                ->map(fn (Collection $g) => [
                    'client_id' => (int) $g->first()->order?->client_id,
                    'name'      => (string) ($g->first()->order?->client?->name ?? '—'),
                    'orders'    => $g->pluck('order_id')->unique()->values()->all(),
                    'kits'      => $g->count(),
                    'food'      => $this->foodDates($g->pluck('date')),
                ])->values()->all();

            $removed = $sent->reject(fn ($p, $id) => $current->has($id))
                ->groupBy('client_id')
                ->map(fn (Collection $g) => [
                    'client_id' => (int) $g->first()['client_id'],
                    'name'      => (string) ($g->first()['name'] ?: '—'),
                    'orders'    => $g->pluck('order_id')->unique()->values()->all(),
                    'kits'      => $g->count(),
                ])->values()->all();

            if ($added || $removed) {
                $out[$shift] = [
                    'label'     => $label,
                    'pushed_at' => $push->pushed_at->format('d.m H:i'),
                    'added'     => $added,
                    'removed'   => $removed,
                ];
            }
        }

        return $out;
    }

    /**
     * Створено день замовлення. Якщо його зміну вже відправлено в ANT — одразу
     * кажемо менеджерам (раз на замовлення і зміну). Ніколи не кидає винятків.
     */
    public function dayCreated(OrderDay $day): void
    {
        try {
            if (! SchemaReady::has('ant_pushes')) {
                return;
            }

            $day->loadMissing('order.client');
            $order = $day->order;
            if (! $order || ! in_array($order->status, ['active', 'new'], true) || $order->separate_courier) {
                return;
            }

            $delivery = $day->resolveDeliveryDate()->startOfDay();
            if ($delivery->lt(today())) {
                return;
            }

            $shift = $this->ant->orderDayIsEvening($day) ? 'evening' : 'morning';
            $push  = $this->pushFor($delivery->format('Y-m-d'), $shift);
            if (! $push || collect($push->points)->contains('day_id', (int) $day->id)) {
                return;
            }

            $key = "late-push:{$order->id}:{$delivery->format('Y-m-d')}:{$shift}";
            if (! Cache::add($key, 1, now()->addHours(18))) {
                return;
            }

            $name = $order->client?->name ?? 'Клієнт';
            app(TelegramService::class)->sendToManager(implode("\n", [
                '⚠️ Замовлення після відправки маршруту',
                "{$name} (замовлення №{$order->id})",
                'Доставка: '.$this->dayLabel($delivery).', '.self::SHIFTS[$shift].'.',
                "Цю зміну вже відправлено в ANT {$push->pushed_at->format('d.m о H:i')} — точки в маршруті немає.",
                'Логістика → «Замовлення →» ще раз на цю зміну, потім перебудувати маршрут.',
            ]));
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * У замовленні змінили графік, час чи дату доставки. День міг переїхати в іншу
     * зміну: у старому відправленому маршруті точка лишилась, а в новому її немає.
     * Кажемо менеджерам одразу (раз на однаковий стан). Ніколи не кидає винятків.
     */
    public function orderMoved(Order $order): void
    {
        try {
            if (! SchemaReady::has('ant_pushes')) {
                return;
            }
            if (! in_array($order->status, ['active', 'new'], true) || $order->separate_courier) {
                return;
            }

            $pushes = AntPush::whereDate('delivery_date', '>=', today()->format('Y-m-d'))->get();
            if ($pushes->isEmpty()) {
                return;
            }

            $days = $order->orderDays()->whereDate('date', '>=', today()->format('Y-m-d'))
                ->orderBy('date')->limit(14)->get();

            $lines = [];
            foreach ($days as $day) {
                $day->setRelation('order', $order);
                $delivery = $day->resolveDeliveryDate()->startOfDay();
                $shift    = $this->ant->orderDayIsEvening($day) ? 'evening' : 'morning';
                $now      = $this->dayLabel($delivery).', '.self::SHIFTS[$shift];
                $food     = $this->dayLabel(Carbon::parse($day->date));

                // Де день стоїть у вже відправлених маршрутах, хоча туди більше не належить.
                $old = [];
                foreach ($pushes as $p) {
                    $point = collect($p->points)->firstWhere('day_id', (int) $day->id);
                    if (! $point) {
                        continue;
                    }
                    $pDate = Carbon::parse($p->delivery_date)->startOfDay();
                    if (! $pDate->equalTo($delivery) || ($point['shift'] ?? '') !== $shift) {
                        $old[] = $this->dayLabel($pDate).', '.(self::SHIFTS[$point['shift'] ?? ''] ?? '');
                    }
                }

                // Чи нову зміну вже відправлено без цієї точки.
                $missing = null;
                if ($delivery->gte(today())) {
                    $push = $this->pushFor($delivery->format('Y-m-d'), $shift);
                    if ($push && ! collect($push->points)->contains('day_id', (int) $day->id)) {
                        $missing = $push;
                    }
                }

                if (! $old && ! $missing) {
                    continue;
                }

                $line = "• раціон {$food}: ";
                $line .= $old ? 'у відправленому маршруті '.implode(' і ', $old)." → тепер {$now}." : "доставка {$now}.";
                if ($delivery->lt(today())) {
                    $line .= ' Ця доставка вже минула — раціон ніхто не повезе.';
                } elseif ($missing) {
                    $line .= " Цю зміну вже відправлено {$missing->pushed_at->format('d.m о H:i')} — точки там немає.";
                } else {
                    $line .= ' Нову зміну ще не відправляли.';
                }
                $lines[] = $line;
            }

            if (! $lines) {
                return;
            }

            $key = 'late-move:'.$order->id.':'.md5(implode('|', $lines));
            if (! Cache::add($key, 1, now()->addHours(18))) {
                return;
            }

            $name = $order->client?->name ?? 'Клієнт';
            $who  = auth()->user()?->name ?? 'не через CRM (скрипт/API)';
            app(TelegramService::class)->sendToManager(implode("\n", [
                '⚠️ Доставку змінено після відправки маршруту',
                "{$name} (замовлення №{$order->id}), змінив: {$who}",
                ...$lines,
                'Логістика → «Замовлення →» ще раз на обидві зміни, потім перебудувати маршрути. Перевірте наліпки.',
            ]));
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * Графік замовлення змінили (ранок ↔ вечір). Дні, які вже пішли в ANT, нікуди
     * не переїжджають: їх везуть так, як відправили, — на день записуємо стару дату
     * й час доставки. Новий графік діє з першого ще не відправленого дня.
     * Ніколи не кидає винятків.
     *
     * @return int скільки днів залишено у відправлених маршрутах
     */
    public function keepSentDays(Order $order, ?string $oldSchedule, ?string $oldTime): int
    {
        try {
            if (! SchemaReady::has('ant_pushes') || ! in_array($order->status, ['active', 'new'], true)) {
                return 0;
            }

            $wasEvening = \App\Services\ScheduleService::isEvening($oldSchedule);
            if ($wasEvening === \App\Services\ScheduleService::isEvening($order->schedule_type)) {
                return 0;
            }

            $kept = [];
            foreach (AntPush::whereDate('delivery_date', '>=', today()->format('Y-m-d'))->orderByDesc('pushed_at')->get() as $push) {
                foreach (collect($push->points)->where('order_id', (int) $order->id) as $point) {
                    $day = OrderDay::find($point['day_id']);
                    if (! $day || isset($kept[$day->id]) || $day->delivery_date_override || $day->delivery_time) {
                        continue;
                    }

                    $evening = ($point['shift'] ?? '') === 'evening';
                    $time    = ($oldTime && ((int) explode(':', $oldTime)[0] >= 12) === $evening)
                        ? $oldTime
                        : ($evening ? '18:00-22:00' : '06:00-10:00');

                    $day->delivery_date_override = Carbon::parse($push->delivery_date)->format('Y-m-d');
                    $day->delivery_time          = $time;
                    $day->save();

                    $kept[$day->id] = '• раціон '.$this->dayLabel(Carbon::parse($day->date)).' — '
                        .$this->dayLabel(Carbon::parse($push->delivery_date)).', '.self::SHIFTS[$evening ? 'evening' : 'morning'];
                }
            }

            if (! $kept) {
                return 0;
            }

            $name = $order->client?->name ?? 'Клієнт';
            $who  = auth()->user()?->name ?? 'не через CRM (скрипт/API)';
            $to   = \App\Services\ScheduleService::isEvening($order->schedule_type) ? 'вечірню' : 'ранкову';
            app(TelegramService::class)->sendToManager(implode("\n", [
                "ℹ️ {$name} (замовлення №{$order->id}): доставку змінено на {$to} ({$who}).",
                'Уже відправлене в маршрут їде без змін:',
                ...array_values($kept),
                'Новий графік діє з наступних днів. Якщо клієнту обіцяли інакше — змініть день вручну й відправте зміну ще раз.',
            ]));

            return count($kept);
        } catch (\Throwable $e) {
            report($e);

            return 0;
        }
    }

    /**
     * Lunch Hub прислав обіди на день, а зміну, якою їх везуть, уже відправили в ANT.
     * Точка обідів у заявку сама не потрапить — кажемо менеджерам перевідправити.
     * Мовчимо, якщо після відправки нічого не змінилось. Ніколи не кидає винятків.
     *
     * @param  array<string, string>  $before  компанія => «комплекти|вікно» до нового знімка
     */
    public function lunchReceived(string $date, array $before): void
    {
        try {
            if (Carbon::parse($date)->lt(now()->startOfDay())) {
                return;
            }

            $after = \App\Models\LunchOrder::whereDate('date', $date)->get();
            $lines = [];
            $seen  = [];

            foreach ($after as $o) {
                $key        = (string) $o->company_id;
                $seen[$key] = true;
                $sets       = (int) $o->sets;
                $state      = $sets.'|'.$o->delivery_window;
                if (($before[$key] ?? '0|') === $state || ($sets === 0 && ! isset($before[$key]))) {
                    continue;
                }

                $window = \App\Services\Kitchen\LunchDelivery::window($o->delivery_window);
                $shift  = \App\Services\Kitchen\LunchDelivery::shiftOf($window);
                $push   = $this->pushFor($date, $shift);
                if (! $push?->pushed_at) {
                    continue;
                }

                $was     = isset($before[$key]) ? (int) explode('|', $before[$key])[0] : 0;
                $lines[] = '• '.$o->company_name.': '.($was > 0 && $was !== $sets ? "було {$was}, стало {$sets}" : "{$sets}").' компл., '
                    .$window[0].'–'.$window[1].' — '.($shift === 'evening' ? 'вечірню' : 'ранкову').' зміну відправлено о '.$push->pushed_at->format('H:i d.m');
            }

            foreach ($before as $key => $state) {
                if (isset($seen[$key]) || (int) explode('|', $state)[0] === 0) {
                    continue;
                }
                $window = \App\Services\Kitchen\LunchDelivery::window(explode('|', $state, 2)[1] ?? null);
                $shift  = \App\Services\Kitchen\LunchDelivery::shiftOf($window);
                if ($this->pushFor($date, $shift)?->pushed_at) {
                    $lines[] = '• обіди компанії скасовано ('.((int) explode('|', $state)[0]).' компл.), а точка лишилась у відправленому маршруті';
                }
            }

            if (! $lines) {
                return;
            }

            if (! Cache::add('late-lunch:'.$date.':'.md5(implode('|', $lines)), 1, now()->addHours(18))) {
                return;
            }

            app(TelegramService::class)->sendToManager(implode("\n", [
                '🍱 Обіди на '.$this->dayLabel(Carbon::parse($date)).' прийшли після відправки маршруту',
                ...$lines,
                'У маршруті цієї точки немає. Логістика → «Замовлення →» ще раз на цю зміну, потім перебудувати маршрути.',
            ]));
        } catch (\Throwable $e) {
            report($e);
        }
    }

    private function dayLabel(Carbon $d): string
    {
        $map = ['Mon' => 'пн', 'Tue' => 'вт', 'Wed' => 'ср', 'Thu' => 'чт', 'Fri' => 'пт', 'Sat' => 'сб', 'Sun' => 'нд'];

        return ($map[$d->format('D')] ?? '').' '.$d->format('d.m');
    }

    private function foodDates(Collection $dates): string
    {
        return $dates->map(fn ($d) => Carbon::parse($d))->unique(fn (Carbon $d) => $d->format('Y-m-d'))->sort()
            ->map(fn (Carbon $d) => $this->dayLabel($d))->join(' + ');
    }
}
