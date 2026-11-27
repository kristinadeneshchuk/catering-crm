<?php

namespace App\Services\Orders;

use App\Models\Client;
use App\Models\Order;
use App\Models\OrderReplacement;

/**
 * Заміни страв із шаблонів клієнта («Спорт» тощо) → записи заміни в замовленні.
 *
 * Шаблон прив'язують до клієнта або до тарифу (лінійка меню); тут його правила «страва → страва»
 * розкладаються в order_replacements живих циклових замовлень, а далі їх бачать
 * виробничий, фасувальний, наліпки й меню клієнта — тим самим шляхом, що й
 * заміну, поставлену менеджером уручну.
 *
 * Записи з шаблону мітяться коментарем «Пакет: <назва>»: лише їх sync і прибирає,
 * коли шаблон зняли з клієнта чи правило видалили. Заміну, яку менеджер поставив
 * на ту саму страву руками, не чіпаємо — вона головніша.
 */
class BundleDishSwaps
{
    public const MARK = 'Пакет: ';

    private const LIVE = ['new', 'active', 'paused'];

    /** Усі живі замовлення клієнта. Повертає, скільки записів додано або змінено. */
    public function syncClient(Client $client): int
    {
        $changed = 0;
        foreach (Order::where('client_id', $client->id)->whereIn('status', self::LIVE)->get() as $order) {
            $changed += $this->sync($order);
        }

        return $changed;
    }

    public function sync(Order $order): int
    {
        // Персональне меню складають по стравах — там цикловій заміні нема чого міняти.
        if (! $order->client_id || $order->usesPersonalMenu()) {
            return 0;
        }

        $wanted = $this->wantedFor($order);
        $changed = 0;

        $existing = OrderReplacement::where('order_id', $order->id)->whereNull('original_product_id')->get();

        foreach ($existing as $rep) {
            if (! str_starts_with((string) $rep->comment, self::MARK)) {
                unset($wanted[$rep->dish_id]); // ручна заміна цієї страви — лишаємо як є
                continue;
            }

            $want = $wanted[$rep->dish_id] ?? null;
            if (! $want) {
                $rep->delete();
                $changed++;
            } elseif ((int) $rep->replacement_dish_id !== $want['dish'] || $rep->comment !== $want['comment']) {
                $rep->update(['replacement_dish_id' => $want['dish'], 'comment' => $want['comment']]);
                $changed++;
            }
            unset($wanted[$rep->dish_id]);
        }

        foreach ($wanted as $dishId => $want) {
            OrderReplacement::create([
                'order_id'            => $order->id,
                'dish_id'             => $dishId,
                'original_product_id' => null,
                'replacement_dish_id' => $want['dish'],
                'force_approved'      => false,
                'comment'             => $want['comment'],
            ]);
            $changed++;
        }

        return $changed;
    }

    /** [dish_id => ['dish' => replacement_dish_id, 'comment' => 'Пакет: …']]; за збігу бере перший шаблон. */
    private function wantedFor(Order $order): array
    {
        $client = Client::with('replacementBundles.dishSwaps')->find($order->client_id);
        // Лінійка тарифу головніша за шаблони клієнта: за неї заплачено саме в цьому замовленні.
        $tariffBundle = $order->tariff_id
            ? \App\Models\Tariff::withTrashed()->with('replacementBundle.dishSwaps')->find($order->tariff_id)?->replacementBundle
            : null;
        $bundles = collect([$tariffBundle])->filter()->concat($client?->replacementBundles ?? [])->unique('id');

        $wanted = [];
        foreach ($bundles as $bundle) {
            foreach ($bundle->dishSwaps as $swap) {
                if (! $swap->replacement_dish_id || $swap->replacement_dish_id === $swap->original_dish_id) {
                    continue;
                }
                $wanted[(int) $swap->original_dish_id] ??= [
                    'dish'    => (int) $swap->replacement_dish_id,
                    'comment' => self::MARK . $bundle->name,
                ];
            }
        }

        return $wanted;
    }
}
