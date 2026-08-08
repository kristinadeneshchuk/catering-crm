<?php

namespace App\Services;

use App\Models\DeliveryZone;
use App\Models\Extra;
use App\Models\Product;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Рахунок оренди.
 *
 * Ціни завжди беруться з бази по строку, а не з того, що прислав браузер:
 * тарифну сходинку в devtools правити легше, ніж здається.
 */
class RentalPricing
{
    /** Кількість діб включно з першою і останньою. */
    public function days(string $from, string $to): int
    {
        return (int) Carbon::parse($from)->diffInDays(Carbon::parse($to)) + 1;
    }

    public function pricePerDay(Product $product, int $days): int
    {
        return $product->priceFor($days);
    }

    public function rentTotal(Product $product, int $days, int $qty = 1): int
    {
        return $this->pricePerDay($product, $days) * $days * $qty;
    }

    /** Економія проти базового тарифу — те, що показує сходинка. */
    public function savings(Product $product, int $days): int
    {
        return ($product->base_price - $this->pricePerDay($product, $days)) * $days;
    }

    /**
     * @param  Collection<int, array{product: Product, days: int, qty: int}>  $items
     * @return array{rent: int, deposit: int}
     */
    public function itemsTotal(Collection $items): array
    {
        return [
            'rent' => $items->sum(fn ($i) => $this->rentTotal($i['product'], $i['days'], $i['qty'])),
            'deposit' => $items->sum(fn ($i) => $i['product']->deposit * $i['qty']),
        ];
    }

    /** @param  Collection<int, array{extra: Extra, qty: int}>  $extras */
    public function extrasTotal(Collection $extras): int
    {
        return $extras->sum(fn ($e) => $e['extra']->price * $e['qty']);
    }

    /**
     * Доставка. Важка техніка від 200 кг самовивозом не видається взагалі —
     * її або привозимо ми, або клієнт не отримає нічого і поїде даремно.
     */
    public function delivery(?DeliveryZone $zone, Collection $items, int $days): int
    {
        if (! $zone) {
            return 0;
        }

        $heaviest = $items->max(fn ($i) => $i['product']->weight_kg) ?? 0;

        // Важку техніку при оренді від 7 днів веземо безкоштовно.
        if ($heaviest >= 100 && $days >= 7) {
            return 0;
        }

        $price = $zone->price;

        if ($heaviest >= 200) {
            $price += 400;   // окрема машина з гідробортом
        } elseif ($heaviest >= 100) {
            $price += 150;   // гідроборт
        }

        return $price;
    }

    public function requiresDelivery(Collection $items): bool
    {
        return $items->contains(fn ($i) => $i['product']->weight_kg >= 200);
    }
}
