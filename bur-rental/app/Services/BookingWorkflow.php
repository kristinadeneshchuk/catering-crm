<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\BookingItem;
use App\Models\UnavailableDate;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Життєвий цикл броні: нова → підтверджена → видана → закрита (або скасована).
 *
 * Тут одне правило, заради якого сервіс і існує: зайняті дати мають з'являтися
 * і зникати разом зі станом броні. Якщо клієнт здав раніше або скасував, дні
 * мусять повернутися в календар того ж моменту — інакше сайт показує «зайнято»
 * на техніку, яка стоїть на полиці.
 */
class BookingWorkflow
{
    public function __construct(private readonly RentalPricing $pricing) {}

    public function confirm(Booking $booking): Booking
    {
        $booking->update(['status' => 'confirmed']);

        return $booking;
    }

    public function issue(Booking $booking): Booking
    {
        $booking->update(['status' => 'issued']);

        return $booking;
    }

    /**
     * Приймання техніки.
     *
     * Фактична дата повернення може не збігтися з плановою в обидва боки:
     * здали раніше — перераховуємо за фактичним строком і звільняємо
     * невикористані дні; привезли пізніше — добираємо прострочені доби
     * за базовим тарифом (знижка за строк на прострочення не діє).
     */
    public function close(Booking $booking, string $returnedOn): Booking
    {
        $returned = Carbon::parse($returnedOn)->startOfDay();
        $from = $booking->date_from->copy()->startOfDay();
        $planned = $booking->date_to->copy()->startOfDay();

        $actualDays = max(1, (int) $from->diffInDays($returned) + 1);
        $overdueDays = max(0, (int) $planned->diffInDays($returned));

        DB::transaction(function () use ($booking, $returned, $planned, $actualDays, $overdueDays) {
            $rent = 0;

            foreach ($booking->items()->whereNotNull('product_id')->with('product.tiers')->get() as $item) {
                $rent += $this->repriceItem($item, $actualDays, $overdueDays);
            }

            $booking->update([
                'status' => 'closed',
                'date_to' => $returned,
                'rent_total' => $rent,
            ]);

            if ($returned->lt($planned)) {
                $this->releaseDates($booking, $returned->copy()->addDay(), $planned);
            } else {
                $this->blockDates($booking, $planned->copy()->addDay(), $returned);
            }
        });

        return $booking->refresh();
    }

    public function cancel(Booking $booking): Booking
    {
        DB::transaction(function () use ($booking) {
            $booking->update(['status' => 'cancelled']);

            // Скасована бронь не має тримати календар.
            $this->releaseDates($booking, $booking->date_from, $booking->date_to);
        });

        return $booking->refresh();
    }

    /** @return int сума по позиції після перерахунку */
    private function repriceItem(BookingItem $item, int $actualDays, int $overdueDays): int
    {
        $product = $item->product;
        $paidDays = max(1, $actualDays - $overdueDays);

        // Оплачений строк — за сходинкою, прострочення — за базовим тарифом.
        $perDay = $this->pricing->pricePerDay($product, $paidDays);
        $total = ($perDay * $paidDays + $product->base_price * $overdueDays) * $item->qty;

        $item->update([
            'days' => $actualDays,
            'price_per_day' => $perDay,
            'total' => $total,
        ]);

        return $total;
    }

    private function releaseDates(Booking $booking, Carbon $from, Carbon $to): void
    {
        $productIds = $booking->items()->whereNotNull('product_id')->pluck('product_id');

        UnavailableDate::whereIn('product_id', $productIds)
            ->where('branch_id', $booking->branch_id)
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->where('reason', 'rented')
            ->delete();
    }

    private function blockDates(Booking $booking, Carbon $from, Carbon $to): void
    {
        foreach ($booking->items()->whereNotNull('product_id')->get() as $item) {
            for ($day = $from->copy(); $day->lte($to); $day->addDay()) {
                UnavailableDate::firstOrCreate([
                    'product_id' => $item->product_id,
                    'branch_id' => $booking->branch_id,
                    'date' => $day->toDateString(),
                ], ['reason' => 'rented']);
            }
        }
    }
}
