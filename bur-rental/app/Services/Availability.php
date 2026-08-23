<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Branch;
use App\Models\Product;
use App\Models\UnavailableDate;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Наявність рахується по екземплярах.
 *
 * Модель зайнята в конкретний день лише тоді, коли зайнято не менше екземплярів,
 * ніж стоїть на складі філії. Три перфоратори на Позняках витримують три
 * паралельні броні — і показувати «зайнято» після першої означало б відмовляти
 * клієнту в техніці, яка лежить на полиці.
 */
class Availability
{
    /** Скільки екземплярів моделі стоїть у філії. */
    public function stock(Product $product, Branch $branch): int
    {
        return (int) DB::table('inventory')
            ->where('product_id', $product->id)
            ->where('branch_id', $branch->id)
            ->value('qty');
    }

    /**
     * Зайнято екземплярів по днях: ['2026-08-14' => 2, …].
     *
     * @return Collection<string, int>
     */
    public function takenByDate(Product $product, Branch $branch, string $from, string $to): Collection
    {
        return UnavailableDate::query()
            ->where('product_id', $product->id)
            ->where('branch_id', $branch->id)
            ->whereBetween('date', [$from, $to])
            ->selectRaw('date, SUM(qty) as taken')
            ->groupBy('date')
            ->pluck('taken', 'date')
            ->mapWithKeys(fn ($taken, $date) => [Carbon::parse($date)->toDateString() => (int) $taken]);
    }

    /** Скільки екземплярів вільно в найзавантаженіший день діапазону. */
    public function freeUnits(Product $product, Branch $branch, string $from, string $to): int
    {
        $peak = $this->takenByDate($product, $branch, $from, $to)->max() ?? 0;

        return max(0, $this->stock($product, $branch) - $peak);
    }

    public function isFree(Product $product, Branch $branch, string $from, string $to, int $qty = 1): bool
    {
        return $this->freeUnits($product, $branch, $from, $to) >= $qty;
    }

    /**
     * Дати, у які модель уже не взяти — саме їх календар показує закресленими.
     *
     * @return list<string>
     */
    public function fullDates(Product $product, Branch $branch, string $from, string $to): array
    {
        $stock = $this->stock($product, $branch);

        return $this->takenByDate($product, $branch, $from, $to)
            ->filter(fn (int $taken) => $taken >= $stock)
            ->keys()
            ->all();
    }

    /**
     * Атомарно перевіряє і займає екземпляри.
     *
     * Два клієнти, які тиснуть «Забронювати» в одну секунду на останній
     * перфоратор, без блокування проходили обидва: кожен встигав побачити
     * «вільно» до того, як інший записав свою бронь. Тепер рядок складу
     * блокується на час транзакції — друга спроба стає в чергу і чесно
     * отримує відмову.
     *
     * @return bool чи вдалося зарезервувати
     */
    public function reserve(Booking $booking, Product $product, int $qty, string $from, string $to): bool
    {
        $branch = $booking->branch()->first();

        if (! $branch) {
            return false;
        }

        DB::table('inventory')
            ->where('product_id', $product->id)
            ->where('branch_id', $branch->id)
            ->lockForUpdate()
            ->first();

        if (! $this->isFree($product, $branch, $from, $to, $qty)) {
            return false;
        }

        $this->hold($booking, $product, $qty, $from, $to);

        return true;
    }

    /** Займає екземпляри під бронь на весь її строк. */
    public function hold(Booking $booking, Product $product, int $qty, string $from, string $to): void
    {
        for ($day = Carbon::parse($from); $day->lte(Carbon::parse($to)); $day->addDay()) {
            UnavailableDate::create([
                'product_id' => $product->id,
                'branch_id' => $booking->branch_id,
                'booking_id' => $booking->id,
                'date' => $day->toDateString(),
                'reason' => 'rented',
                'qty' => $qty,
            ]);
        }
    }

    /**
     * Звільняє екземпляри цієї броні у вказаному вікні.
     *
     * Прив'язка до booking_id принципова: без неї дострокова здача однієї броні
     * зняла б блокування чужої, яка стоїть на ті самі дати.
     */
    public function release(Booking $booking, ?string $from = null, ?string $to = null): void
    {
        UnavailableDate::where('booking_id', $booking->id)
            ->when($from && $to, fn ($q) => $q->whereBetween('date', [$from, $to]))
            ->delete();
    }
}
