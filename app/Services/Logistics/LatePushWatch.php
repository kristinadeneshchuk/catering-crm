<?php

namespace App\Services\Logistics;

use App\Models\AntPush;
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
