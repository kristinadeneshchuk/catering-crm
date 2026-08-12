<?php

namespace App\Services\Inbox;

use App\Models\CalorieRange;
use App\Models\Tariff;
use App\Models\TariffPrice;
use Illuminate\Validation\ValidationException;

/**
 * Розрахунок вартості замовлення для зовнішніх систем (Telegram Inbox).
 *
 * Формула повторює Order::booted() один в один, щоб API і адмінка ніколи не
 * розійшлись у сумі:
 *
 *   range         = calorie_ranges, що охоплює число калорій
 *   price_per_day = tariff_prices[tariff][range]
 *   subtotal      = price_per_day × days
 *   discount      = percent → subtotal × value / 100
 *                   fixed   → min(value, subtotal)
 *   total         = max(0, subtotal − discount)
 *
 * Пошук діапазону навмисно без сортування — рівно як у решті коду. При
 * перекритті діапазонів (наприклад 2400 ккал потрапляє і в «2400-2500», і в
 * «2100-2500») база повертає той самий рядок, що й для адмінки. Додавати сюди
 * власне правило вибору не можна: це розвело б суму в API і в CRM.
 *
 * Доставка в ціну замовлення не входить — у CRM вона живе окремо, у полі
 * order_days.extra_delivery_fee, і на етап оформлення не впливає.
 */
class PricingService
{
    /**
     * Діапазон калорій для заданого калоражу. null — жоден не підходить.
     */
    public function resolveRange(int $calories): ?CalorieRange
    {
        return CalorieRange::where('min_kcal', '<=', $calories)
            ->where('max_kcal', '>=', $calories)
            ->first();
    }

    /**
     * Ціна за день або null, якщо у матриці для цієї пари нема ненульового рядка.
     */
    public function pricePerDay(int $tariffId, int $calorieRangeId): ?float
    {
        $price = TariffPrice::where('tariff_id', $tariffId)
            ->where('calorie_range_id', $calorieRangeId)
            ->value('price_per_day');

        return $price > 0 ? (float) $price : null;
    }

    /**
     * Повний розрахунок. Кидає ValidationException (422) з людською причиною,
     * якщо порахувати неможливо — API не має права повернути нуль як «ціну».
     *
     * @param  array{type?: ?string, value?: float|int|null}  $discount
     */
    public function quote(Tariff $tariff, int $calories, int $days, array $discount = []): array
    {
        if ($days < 1) {
            throw ValidationException::withMessages([
                'days' => 'Кількість днів має бути щонайменше 1.',
            ]);
        }

        if ($tariff->min_days && $days < $tariff->min_days) {
            throw ValidationException::withMessages([
                'days' => "Тариф «{$tariff->name}» доступний від {$tariff->min_days} днів, а замовлено {$days}.",
            ]);
        }

        $range = $this->resolveRange($calories);

        if (! $range) {
            throw ValidationException::withMessages([
                'calories' => "Жоден діапазон калорій не охоплює {$calories} ккал.",
            ]);
        }

        $pricePerDay = $this->pricePerDay($tariff->id, $range->id);

        if ($pricePerDay === null) {
            throw ValidationException::withMessages([
                'tariff_id' => "Тариф «{$tariff->name}» не має ціни для {$calories} ккал (діапазон «{$range->name}»).",
            ]);
        }

        $subtotal = round($pricePerDay * $days, 2);
        $discountAmount = $this->discountAmount($subtotal, $discount);

        return [
            'currency'      => 'UAH',
            'calories'      => $calories,
            'calorie_range' => [
                'id'           => $range->id,
                'name'         => $range->name,
                'min_calories' => (int) $range->min_kcal,
                'max_calories' => (int) $range->max_kcal,
            ],
            'days'           => $days,
            'price_per_day'  => $pricePerDay,
            'subtotal'       => $subtotal,
            'discount'       => $discountAmount,
            'delivery_price' => 0.0,
            'total'          => round(max(0, $subtotal - $discountAmount), 2),
        ];
    }

    /**
     * Знижка рівня замовлення. Та сама математика, що в Order::calculateOrderDiscount().
     *
     * @param  array{type?: ?string, value?: float|int|null}  $discount
     */
    public function discountAmount(float $subtotal, array $discount): float
    {
        $type  = $discount['type'] ?? null;
        $value = (float) ($discount['value'] ?? 0);

        if (! $type || $value <= 0) {
            return 0.0;
        }

        return match ($type) {
            'percent' => round($subtotal * $value / 100, 2),
            'fixed'   => round(min($value, $subtotal), 2),
            default   => 0.0,
        };
    }

    /**
     * Нормалізує знижку з запиту. Inbox шле або число (разова знижка в гривнях),
     * або об'єкт {type, value}.
     */
    public function normalizeDiscount(mixed $raw): array
    {
        if (is_array($raw)) {
            return [
                'type'  => $raw['type'] ?? null,
                'value' => $raw['value'] ?? null,
            ];
        }

        if (is_numeric($raw) && (float) $raw > 0) {
            return ['type' => 'fixed', 'value' => (float) $raw];
        }

        return ['type' => null, 'value' => null];
    }
}
