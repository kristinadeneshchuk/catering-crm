<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Branch;
use App\Models\Lead;
use App\Models\Product;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class BookingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    private function payload(array $overrides = []): array
    {
        $product = Product::where('slug', 'bosch-gbh-2-26-dre')->firstOrFail();
        $branch = Branch::where('slug', 'poznyaky')->firstOrFail();

        return array_replace_recursive([
            'items' => [[
                'product_id' => $product->id,
                'qty' => 1,
                'from' => Carbon::today()->toDateString(),
                'to' => Carbon::today()->addDays(4)->toDateString(),
            ]],
            'branch_id' => $branch->id,
            'client_type' => 'person',
            'name' => 'Олег',
            'phone' => '+380 67 245 80 80',
            'fulfilment' => 'self',
            'payment' => 'card',
            'deposit_way' => 'card-hold',
        ], $overrides);
    }

    public function test_booking_is_created_and_priced_from_the_database(): void
    {
        $response = $this->post('/booking', $this->payload());

        $booking = Booking::latest('id')->firstOrFail();
        $response->assertRedirect(route('booking.show', $booking));

        // 5 днів → тариф «3–6 днів» = 290 ₴, а не базові 350.
        $this->assertSame(5 * 290, $booking->rent_total);
        $this->assertSame(1500, $booking->deposit_total);
        $this->assertSame(5 * 290 + 1500, $booking->payable);
    }

    public function test_client_supplied_prices_are_ignored(): void
    {
        // Ціна в запиті не приймається взагалі — рахунок завжди з бази.
        $this->post('/booking', $this->payload([
            'items' => [['price_per_day' => 1, 'total' => 1]],
        ]));

        $this->assertSame(5 * 290, Booking::latest('id')->firstOrFail()->rent_total);
    }

    public function test_booked_dates_disappear_from_availability(): void
    {
        $product = Product::where('slug', 'bosch-gbh-2-26-dre')->firstOrFail();
        $branch = Branch::where('slug', 'poznyaky')->firstOrFail();

        $this->post('/booking', $this->payload());

        // Кожна доба строку має стати зайнятою — рахувати приріст рядків не можна,
        // частина дат могла бути зайнята ще до броні.
        for ($day = 0; $day < 5; $day++) {
            $this->assertDatabaseHas('unavailable_dates', [
                'product_id' => $product->id,
                'branch_id' => $branch->id,
                'date' => Carbon::today()->addDays($day)->toDateString(),
            ]);
        }
    }

    public function test_company_booking_requires_edrpou_and_email(): void
    {
        $this->post('/booking', $this->payload([
            'client_type' => 'company',
            'company' => 'ТОВ «Моноліт-Буд»',
        ]))->assertSessionHasErrors(['edrpou', 'email']);
    }

    public function test_phone_format_is_validated(): void
    {
        $this->post('/booking', $this->payload(['phone' => '0672458080']))
            ->assertSessionHasErrors('phone');
    }

    public function test_callback_lead_is_stored(): void
    {
        $this->post('/leads', [
            'kind' => 'callback',
            'name' => 'Світлана',
            'phone' => '+380 67 245 80 80',
            'context' => 'instrument/bosch-gbh-2-26-dre',
        ])->assertRedirect();

        $this->assertSame('Світлана', Lead::where('phone', '+380 67 245 80 80')->firstOrFail()->name);
    }
}
