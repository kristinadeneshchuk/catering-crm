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
     * Від цієї ваги техніка важка: самовивозом не видається (без гідроборта
     * її вдвох у причіп не завантажити — або травма, або побита плита), а
     * доставка при оренді від 7 днів безкоштовна. Перевіряє StoreBookingRequest.
     */
    public const HEAVY_KG = 100;

    /** Від цієї ваги потрібна окрема машина, а не бус із гідробортом. */
    public const TRUCK_KG = 200;

    public const HOIST_FEE = 150;   // гідроборт, техніка від HEAVY_KG

    public const TRUCK_FEE = 400;   // окрема машина, техніка від TRUCK_KG

    /** Від стількох днів оренди важку техніку веземо безкоштовно. */
    public const FREE_DELIVERY_DAYS = 7;

    /**
     * Ті самі правила для форми бронювання: вона показує суму доставки до
     * відправлення, і ця сума мусить збігатися з тим, що порахує delivery().
     */
    public static function deliveryRules(): array
    {
        return [
            'heavyKg' => self::HEAVY_KG,
            'truckKg' => self::TRUCK_KG,
            'hoistFee' => self::HOIST_FEE,
            'truckFee' => self::TRUCK_FEE,
            'freeDays' => self::FREE_DELIVERY_DAYS,
        ];
    }

    public function delivery(?DeliveryZone $zone, Collection $items, int $days): int
    {
        if (! $zone) {
            return 0;
        }

        $heaviest = $items->max(fn ($i) => $i['product']->weight_kg) ?? 0;

        // Важку техніку при оренді від 7 днів веземо безкоштовно.
        if ($heaviest >= self::HEAVY_KG && $days >= self::FREE_DELIVERY_DAYS) {
            return 0;
        }

        $price = $zone->price;

        if ($heaviest >= self::TRUCK_KG) {
            $price += self::TRUCK_FEE;
        } elseif ($heaviest >= self::HEAVY_KG) {
            $price += self::HOIST_FEE;
        }

        return $price;
    }
}
