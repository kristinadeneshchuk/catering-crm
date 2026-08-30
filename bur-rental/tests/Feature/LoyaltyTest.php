<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Branch;
use App\Models\Client;
use App\Models\Product;
use App\Services\BookingWorkflow;
use App\Services\Loyalty;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class LoyaltyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    private function client(int $completedRentals = 0): Client
    {
        $client = Client::create(['phone' => '380672458080']);

        for ($i = 0; $i < $completedRentals; $i++) {
            Booking::create([
                'number' => 'BUR-TEST-'.$client->id.'-'.$i,
                'client_id' => $client->id,
                'branch_id' => Branch::first()->id,
                'client_type' => 'person',
                'phone' => '+380 67 245 80 80',
                'fulfilment' => 'self',
                'payment' => 'card',
                'deposit_way' => 'card-hold',
                'status' => 'closed',
                'date_from' => Carbon::today()->subDays(30),
                'date_to' => Carbon::today()->subDays(28),
                'rent_total' => 500,
            ]);
        }

        return $client->fresh();
    }

    private function payload(array $overrides = []): array
    {
        return array_replace_recursive([
            'items' => [[
                'product_id' => Product::where('slug', 'bosch-gbh-2-26-dre')->firstOrFail()->id,
                'qty' => 1,
                'from' => Carbon::today()->toDateString(),
                'to' => Carbon::today()->addDays(4)->toDateString(),
            ]],
            'branch_id' => Branch::where('slug', 'poznyaky')->firstOrFail()->id,
            'client_type' => 'person',
            'name' => 'Олег',
            'phone' => '+380 67 245 80 80',
            'fulfilment' => 'self',
            'payment' => 'card',
            'deposit_way' => 'card-hold',
        ], $overrides);
    }

    public function test_new_client_gets_no_discount(): void
    {
        $this->assertSame(0, app(Loyalty::class)->percentFor($this->client()));
    }

    public function test_discount_grows_with_completed_rentals(): void
    {
        $loyalty = app(Loyalty::class);

        foreach ([2 => 0, 3 => 3, 7 => 5, 15 => 7] as $completed => $percent) {
            Client::query()->delete();

            $this->assertSame(
                $percent,
                $loyalty->percentFor($this->client($completed)),
                "після {$completed} оренд має бути {$percent}%"
            );
        }
    }

    public function test_only_closed_rentals_count(): void
    {
        $client = $this->client(3);

        // Три завершені дають знижку; активні й скасовані — ні, бо вони ще
        // можуть не відбутися.
        $this->assertSame(3, app(Loyalty::class)->percentFor($client));

        Booking::where('client_id', $client->id)->update(['status' => 'issued']);

        $this->assertSame(0, app(Loyalty::class)->percentFor($client->fresh()));
    }

    public function test_manual_discount_beats_the_ladder_but_not_the_cap(): void
    {
        $client = $this->client(15);   // сходинка дала б 7%

        $client->update(['discount_percent' => 9]);
        $this->assertSame(9, app(Loyalty::class)->percentFor($client->fresh()));

        // «Домовився з бригадиром» не має перетворюватись на мінус тридцять.
        $client->update(['discount_percent' => 30]);
        $this->assertSame(
            config('loyalty.max_percent'),
            app(Loyalty::class)->percentFor($client->fresh())
        );
    }

    public function test_discount_applies_to_rent_only(): void
    {
        $this->client(3);

        $this->post('/booking', $this->payload());

        $booking = Booking::latest('id')->firstOrFail();

        // 5 днів × 210 ₴ = 1050 ₴ оренди, знижка 3% = 31 ₴ (донизу).
        $this->assertSame(1050, $booking->rent_total);
        $this->assertSame(3, $booking->discount_percent);
        $this->assertSame(31, $booking->discount_total);

        // Застава не дешевшає: це не дохід, вона повертається цілком.
        $this->assertSame(1500, $booking->deposit_total);
        $this->assertSame(1050 + 1500 - 31, $booking->payable);
    }

    public function test_discount_is_given_by_phone_even_without_logging_in(): void
    {
        $this->client(3);

        // Постійний клієнт заслуговує на свої відсотки й тоді, коли забув
        // увійти в кабінет — номер той самий, у місцевому написанні.
        $this->post('/booking', $this->payload(['phone' => '0672458080']));

        $this->assertSame(3, Booking::latest('id')->firstOrFail()->discount_percent);
    }

    public function test_stranger_gets_nothing(): void
    {
        $this->post('/booking', $this->payload(['phone' => '+380 99 111 22 33']));

        $booking = Booking::latest('id')->firstOrFail();

        $this->assertSame(0, $booking->discount_percent);
        $this->assertSame(0, $booking->discount_total);
    }

    public function test_client_cannot_ask_for_a_discount_in_the_request(): void
    {
        $this->post('/booking', $this->payload([
            'discount_percent' => 50,
            'discount_total' => 900,
        ]));

        // Знижку рахує тільки сервер — так само, як і ціни.
        $booking = Booking::latest('id')->firstOrFail();

        $this->assertSame(0, $booking->discount_percent);
        $this->assertSame(0, $booking->discount_total);
    }

    public function test_discount_is_recomputed_when_the_booking_is_closed(): void
    {
        $this->client(3);

        $this->post('/booking', $this->payload());
        $booking = Booking::latest('id')->firstOrFail();

        // Здали на два дні раніше: оренда подешевшала, знижка — разом із нею.
        app(BookingWorkflow::class)->close($booking, Carbon::today()->addDays(2)->toDateString());

        $booking->refresh();

        $this->assertSame(3, $booking->discount_percent);
        $this->assertSame(
            app(Loyalty::class)->amount($booking->rent_total, 3),
            $booking->discount_total
        );
    }

    public function test_cabinet_shows_the_level_and_what_is_left_to_the_next_one(): void
    {
        $client = $this->client(3);

        $this->actingAs($client, 'client');

        $this->get('/cabinet')
            ->assertOk()
            ->assertSee('знижка −3%')
            ->assertSee('ще 3 оренди до наступного рівня');
    }
}
