<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Branch;
use App\Models\Product;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TelegramAlertsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);

        config([
            'services.telegram.bot_token' => 'test-token',
            'services.telegram.chat_id' => '111222',
        ]);

        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
    }

    private function makeBooking(): Booking
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

    public function test_manager_gets_a_telegram_message_about_a_new_booking(): void
    {
        $booking = $this->makeBooking();

        Http::assertSent(function (Request $request) use ($booking) {
            return str_contains($request->url(), 'api.telegram.org/bottest-token/sendMessage')
                && $request['chat_id'] === '111222'
                && str_contains($request['text'], $booking->number)
                && str_contains($request['text'], '+380 67 245 80 80')
                && str_contains($request['text'], 'GBH 2-26');
        });
    }

    public function test_manager_gets_a_message_about_a_callback_lead(): void
    {
        $this->post('/leads', [
            'kind' => 'callback',
            'name' => 'Світлана',
            'phone' => '+380 63 111 22 33',
            'context' => 'instrument/bosch-gbh-2-26-dre',
        ]);

        Http::assertSent(fn (Request $request) => str_contains($request['text'] ?? '', 'Передзвоніть мені')
            && str_contains($request['text'], 'Світлана'));
    }

    public function test_without_a_token_nothing_is_sent_and_booking_still_works(): void
    {
        config(['services.telegram.bot_token' => null]);

        $booking = $this->makeBooking();

        // Бронь створена, а в Telegram ніхто не стукав.
        $this->assertSame('new', $booking->status);
        Http::assertNothingSent();
    }
}
