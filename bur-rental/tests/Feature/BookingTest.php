<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Branch;
use App\Models\DeliveryZone;
use App\Models\Lead;
use App\Models\Product;
use App\Services\Availability;
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

        // 5 днів → тариф «3–6 днів» = 210 ₴, а не базові 250.
        $this->assertSame(5 * 210, $booking->rent_total);
        $this->assertSame(1500, $booking->deposit_total);
        $this->assertSame(5 * 210 + 1500, $booking->payable);
    }

    public function test_client_supplied_prices_are_ignored(): void
    {
        // Ціна в запиті не приймається взагалі — рахунок завжди з бази.
        $this->post('/booking', $this->payload([
            'items' => [['price_per_day' => 1, 'total' => 1]],
        ]));

        $this->assertSame(5 * 210, Booking::latest('id')->firstOrFail()->rent_total);
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

    public function test_several_customers_can_rent_the_same_model_in_parallel(): void
    {
        $product = Product::with('tiers')->where('slug', 'bosch-gbh-2-26-dre')->firstOrFail();
        $branch = Branch::where('slug', 'poznyaky')->firstOrFail();
        $from = Carbon::today()->toDateString();
        $to = Carbon::today()->addDays(4)->toDateString();

        $availability = app(Availability::class);
        $free = $availability->freeUnits($product, $branch, $from, $to);

        $this->assertGreaterThan(1, $free, 'ходова позиція має стояти в кількох екземплярах');

        // Забираємо всі вільні екземпляри — кожна бронь має пройти без зауважень.
        for ($i = 0; $i < $free; $i++) {
            $this->post('/booking', $this->payload())->assertSessionHas('taken', []);
        }

        $this->assertSame(0, $availability->freeUnits($product, $branch, $from, $to));

        // Наступний клієнт на ті самі дати вже впирається у склад.
        $this->post('/booking', $this->payload())
            ->assertSessionHas('taken', fn (array $taken) => in_array($product->name, $taken, true));
    }

    public function test_booking_beyond_stock_never_holds_more_units_than_the_shelf_has(): void
    {
        $product = Product::with('tiers')->where('slug', 'bosch-gbh-2-26-dre')->firstOrFail();
        $branch = Branch::where('slug', 'poznyaky')->firstOrFail();
        $from = Carbon::today()->toDateString();
        $to = Carbon::today()->addDays(4)->toDateString();

        $availability = app(Availability::class);
        $stock = $availability->stock($product, $branch);

        // Свідомо перебираємо склад: заявок більше, ніж перфораторів на полиці.
        for ($i = 0; $i < $stock + 3; $i++) {
            $this->post('/booking', $this->payload());
        }

        // Ключова перевірка: зайнято рівно стільки, скільки є. Без блокування
        // в транзакції зайнятість переповзала за склад і календар починав
        // обіцяти техніку, якої немає.
        $peak = $availability->takenByDate($product, $branch, $from, $to)->max();
        $this->assertSame($stock, $peak);
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
        // Не номер — відмова.
        $this->post('/booking', $this->payload(['phone' => '245-80-80']))
            ->assertSessionHasErrors('phone');

        // А от місцеве написання приймається: людина набирає так, як звикла,
        // а в базу номер лягає канонічним.
        $this->post('/booking', $this->payload(['phone' => '0672458080']))
            ->assertSessionHasNoErrors();

        $this->assertSame('+380 67 245 80 80', Booking::latest('id')->firstOrFail()->phone);
    }

    public function test_lead_form_stops_accepting_after_a_flood(): void
    {
        $lead = [
            'kind' => 'callback',
            'name' => 'Світлана',
            'phone' => '+380 67 245 80 80',
        ];

        for ($i = 0; $i < 10; $i++) {
            $this->post('/leads', $lead)->assertRedirect();
        }

        // Одинадцята заявка за годину — це вже скрипт, а не людина.
        $this->post('/leads', $lead)->assertSessionHasErrors('phone');
        $this->assertSame(10, Lead::where('phone', $lead['phone'])->count());
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

    /**
     * Самовивіз важкої техніки. Поріг — RentalPricing::HEAVY_KG (100 кг):
     * реверсивну плиту на 160 кг удвох у причіп не завантажити. Форма цей
     * варіант вимикає, але кошик у localStorage, і запит можна зібрати руками.
     */
    public function test_heavy_equipment_is_not_released_for_self_pickup(): void
    {
        $plate = Product::where('slug', 'wacker-dpu-2540')->firstOrFail();   // 160 кг
        $before = Booking::count();

        $this->post('/booking', $this->payload(['items' => [['product_id' => $plate->id]]]))
            ->assertSessionHasErrors('fulfilment');

        $this->assertSame($before, Booking::count());
    }

    public function test_heavy_equipment_goes_by_delivery_free_from_seven_days(): void
    {
        $plate = Product::where('slug', 'wacker-dpu-2540')->firstOrFail();
        $zone = DeliveryZone::where('slug', 'city')->firstOrFail();

        $this->post('/booking', $this->payload([
            'items' => [['product_id' => $plate->id, 'to' => Carbon::today()->addDays(6)->toDateString()]],
            'fulfilment' => 'delivery',
            'delivery_zone_id' => $zone->id,
            'address' => 'вул. Садова, 12',
        ]))->assertSessionHasNoErrors();

        $this->assertSame(0, Booking::latest('id')->firstOrFail()->delivery_total);
    }

    public function test_light_plate_below_the_threshold_can_still_be_picked_up(): void
    {
        $plate = Product::where('slug', 'wacker-bp-1050')->firstOrFail();   // 90 кг

        $this->post('/booking', $this->payload(['items' => [['product_id' => $plate->id]]]))
            ->assertSessionHasNoErrors();

        $this->assertSame('self', Booking::latest('id')->firstOrFail()->fulfilment);
    }

    public function test_product_card_does_not_promise_pickup_for_heavy_equipment(): void
    {
        $this->get(route('product', Product::where('slug', 'wacker-dpu-2540')->firstOrFail(), false))
            ->assertSee('Тільки доставка з гідробортом')
            ->assertDontSee('Самовивіз ·', false);

        $this->get(route('product', Product::where('slug', 'wacker-bp-1050')->firstOrFail(), false))
            ->assertSee('Самовивіз ·', false);
    }
}
