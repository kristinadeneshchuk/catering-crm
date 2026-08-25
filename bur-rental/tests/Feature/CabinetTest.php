<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Branch;
use App\Models\Client;
use App\Models\ClientLoginCode;
use App\Models\Product;
use App\Services\Clients\LoginCodes;
use App\Services\Messaging\Sms;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CabinetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    /** Проходить обидва кроки входу і повертає код, який справді надіслали. */
    private function login(string $phone = '+380 67 245 80 80'): Client
    {
        $code = app(LoginCodes::class)->issue($phone);
        $client = app(LoginCodes::class)->verify($phone, $code);

        $this->actingAs($client, 'client');

        return $client;
    }

    public function test_login_by_phone_and_code(): void
    {
        // Підміняємо канал доставки: у тесті ми і є «SMS».
        $sent = new class implements Sms
        {
            public string $code = '';

            public function send(string $phone, string $text): void
            {
                preg_match('~\d{6}~', $text, $m);
                $this->code = $m[0] ?? '';
            }
        };

        $this->app->instance(Sms::class, $sent);

        $this->post('/cabinet/login', ['phone' => '+380 67 245 80 80'])
            ->assertRedirect(route('cabinet.code'));

        $this->assertMatchesRegularExpression('~^\d{6}$~', $sent->code);

        $this->post('/cabinet/code', ['code' => $sent->code])
            ->assertRedirect(route('cabinet'));

        $this->assertAuthenticatedAs(Client::where('phone', '380672458080')->firstOrFail(), 'client');
    }

    public function test_code_is_not_shown_on_screen_by_default(): void
    {
        // Показ коду на екрані — режим тестового майданчика. На бойовому це
        // означало б віддати будь-кому чужий кабінет.
        config(['clients.show_code_on_screen' => true, 'app.noindex' => false]);

        $this->post('/cabinet/login', ['phone' => '+380 67 245 80 80']);

        $this->get('/cabinet/code')->assertOk()->assertDontSee('Тестовий режим');
    }

    public function test_wrong_code_is_rejected_and_attempts_run_out(): void
    {
        $codes = app(LoginCodes::class);
        $codes->issue('+380 67 245 80 80');

        $this->assertNull($codes->verify('+380 67 245 80 80', '000000'));
        $this->assertNull($codes->verify('+380 67 245 80 80', '000001'));
        $this->assertNull($codes->verify('+380 67 245 80 80', '000002'));

        // Три спроби вичерпані — код мертвий, навіть правильний.
        $this->assertSame(0, $codes->attemptsLeft('+380 67 245 80 80'));
    }

    public function test_expired_code_does_not_work(): void
    {
        $codes = app(LoginCodes::class);
        $code = $codes->issue('+380 67 245 80 80');

        Carbon::setTestNow(now()->addMinutes(LoginCodes::LIFETIME_MINUTES + 1));

        $this->assertNull($codes->verify('+380 67 245 80 80', $code));

        Carbon::setTestNow();
    }

    public function test_issuing_a_new_code_kills_the_previous_one(): void
    {
        $codes = app(LoginCodes::class);
        $first = $codes->issue('+380 67 245 80 80');
        $codes->issue('+380 67 245 80 80');

        // Стара SMS, знайдена через тиждень, не має відкривати кабінет.
        $this->assertNull($codes->verify('+380 67 245 80 80', $first));
    }

    public function test_phone_is_stored_in_one_shape_whatever_was_typed(): void
    {
        $codes = app(LoginCodes::class);

        $client = $codes->verify('0672458080', $codes->issue('+380 67 245 80 80'));

        $this->assertNotNull($client, 'той самий номер у іншому написанні — той самий вхід');
        $this->assertSame('380672458080', $client->phone);
        $this->assertSame('+380 67 245 80 80', $client->display_phone);
    }

    public function test_bookings_made_before_the_first_login_show_up_in_history(): void
    {
        $product = Product::where('slug', 'bosch-gbh-2-26-dre')->firstOrFail();
        $branch = Branch::where('slug', 'poznyaky')->firstOrFail();

        $this->post('/booking', [
            'items' => [[
                'product_id' => $product->id,
                'qty' => 1,
                'from' => Carbon::today()->toDateString(),
                'to' => Carbon::today()->addDays(2)->toDateString(),
            ]],
            'branch_id' => $branch->id,
            'client_type' => 'person',
            'name' => 'Олег',
            'phone' => '0672458080',
            'fulfilment' => 'self',
            'payment' => 'card',
            'deposit_way' => 'card-hold',
        ]);

        $number = Booking::latest('id')->firstOrFail()->number;

        $this->login('+380 67 245 80 80');

        $this->get('/cabinet')->assertOk()->assertSee($number);
    }

    public function test_cabinet_is_closed_to_guests(): void
    {
        $this->get('/cabinet')->assertRedirect(route('cabinet.login'));
        $this->get('/cabinet/profile')->assertRedirect(route('cabinet.login'));
    }

    public function test_someone_elses_booking_is_not_shown(): void
    {
        $this->login('+380 67 245 80 80');

        $stranger = Booking::whereNull('client_id')->firstOrFail();

        $this->get(route('cabinet.booking', $stranger))->assertNotFound();
    }

    public function test_profile_saves_details_but_not_the_phone(): void
    {
        $client = $this->login();

        $this->put('/cabinet/profile', [
            'name' => 'Оксана',
            'email' => 'oksana@example.com',
            'phone' => '+380 99 999 99 99',
        ])->assertRedirect(route('cabinet.profile'));

        $client->refresh();

        $this->assertSame('Оксана', $client->name);
        // Телефон — це логін, форма профілю його не чіпає.
        $this->assertSame('380672458080', $client->phone);
    }

    public function test_client_cannot_reach_the_admin_panel(): void
    {
        $this->login();

        // Клієнти живуть в іншому guard'і, тож для Filament це гість.
        $this->get('/admin')->assertRedirectContains('/admin/login');
    }

    public function test_login_code_is_never_stored_in_plain_text(): void
    {
        $code = app(LoginCodes::class)->issue('+380 67 245 80 80');

        $record = ClientLoginCode::latest('id')->firstOrFail();

        $this->assertNotSame($code, $record->code_hash);
        $this->assertTrue(Hash::check($code, $record->code_hash));
    }
}
