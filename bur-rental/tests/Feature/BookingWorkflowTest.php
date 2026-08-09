<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Branch;
use App\Models\Product;
use App\Models\UnavailableDate;
use App\Services\BookingWorkflow;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class BookingWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private BookingWorkflow $workflow;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->workflow = app(BookingWorkflow::class);
    }

    /** Бронь на 5 днів по перфоратору: 5 × 290 ₴ за тарифом «3–6 днів». */
    private function fiveDayBooking(): Booking
    {
        $this->post('/booking', [
            'items' => [[
                'product_id' => Product::where('slug', 'bosch-gbh-2-26-dre')->value('id'),
                'qty' => 1,
                'from' => Carbon::today()->toDateString(),
                'to' => Carbon::today()->addDays(4)->toDateString(),
            ]],
            'branch_id' => Branch::where('slug', 'poznyaky')->value('id'),
            'client_type' => 'person',
            'name' => 'Олег',
            'phone' => '+380 67 245 80 80',
            'fulfilment' => 'self',
            'payment' => 'card',
            'deposit_way' => 'card-hold',
        ]);

        return Booking::latest('id')->firstOrFail();
    }

    public function test_early_return_reprices_by_the_actual_term(): void
    {
        $booking = $this->fiveDayBooking();
        $this->assertSame(5 * 290, $booking->rent_total);

        // Здали на третій день: тариф «3–6 днів» лишається, але днів уже три.
        $closed = $this->workflow->close($booking, Carbon::today()->addDays(2)->toDateString());

        $this->assertSame('closed', $closed->status);
        $this->assertSame(3 * 290, $closed->rent_total);
    }

    public function test_early_return_frees_the_unused_days(): void
    {
        $booking = $this->fiveDayBooking();
        $productId = $booking->items->first()->product_id;

        $this->workflow->close($booking, Carbon::today()->addDays(2)->toDateString());

        // Четвертий і п'ятий день мають повернутися в календар.
        $stillBusy = UnavailableDate::where('product_id', $productId)
            ->where('branch_id', $booking->branch_id)
            ->whereIn('date', [
                Carbon::today()->addDays(3)->toDateString(),
                Carbon::today()->addDays(4)->toDateString(),
            ])
            ->where('reason', 'rented')
            ->count();

        $this->assertSame(0, $stillBusy);
    }

    public function test_late_return_charges_overdue_days_at_the_base_rate(): void
    {
        $booking = $this->fiveDayBooking();

        // Привезли на два дні пізніше: 5 днів за сходинкою + 2 доби по 350 ₴.
        $closed = $this->workflow->close($booking, Carbon::today()->addDays(6)->toDateString());

        $this->assertSame(5 * 290 + 2 * 350, $closed->rent_total);
        $this->assertSame(7, $closed->items->first()->fresh()->days);
    }

    public function test_late_return_blocks_the_extra_days(): void
    {
        $booking = $this->fiveDayBooking();
        $productId = $booking->items->first()->product_id;

        $this->workflow->close($booking, Carbon::today()->addDays(6)->toDateString());

        $this->assertDatabaseHas('unavailable_dates', [
            'product_id' => $productId,
            'branch_id' => $booking->branch_id,
            'date' => Carbon::today()->addDays(6)->toDateString(),
        ]);
    }

    public function test_cancelling_returns_the_dates_to_the_calendar(): void
    {
        $booking = $this->fiveDayBooking();
        $productId = $booking->items->first()->product_id;

        $this->workflow->cancel($booking);

        $this->assertSame('cancelled', $booking->fresh()->status);

        $busy = UnavailableDate::where('product_id', $productId)
            ->where('branch_id', $booking->branch_id)
            ->whereBetween('date', [
                Carbon::today()->toDateString(),
                Carbon::today()->addDays(4)->toDateString(),
            ])
            ->where('reason', 'rented')
            ->count();

        $this->assertSame(0, $busy);
    }

    public function test_service_blocks_survive_a_cancellation(): void
    {
        $booking = $this->fiveDayBooking();
        $productId = $booking->items->first()->product_id;

        // Ручне блокування на сервіс усередині строку броні.
        UnavailableDate::where('product_id', $productId)
            ->where('branch_id', $booking->branch_id)
            ->whereDate('date', Carbon::today()->addDays(1))
            ->update(['reason' => 'service']);

        $this->workflow->cancel($booking);

        // Скасування броні не має знімати сервісне блокування.
        $this->assertDatabaseHas('unavailable_dates', [
            'product_id' => $productId,
            'date' => Carbon::today()->addDay()->toDateString(),
            'reason' => 'service',
        ]);
    }
}
