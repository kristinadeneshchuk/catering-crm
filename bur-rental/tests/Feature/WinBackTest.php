<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Branch;
use App\Models\Client;
use App\Services\Messaging\Sms;
use App\Services\WinBack;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Межа між нагадуванням і спамом. Кожен тест тут — про те, кому писати НЕ треба.
 */
class WinBackTest extends TestCase
{
    use RefreshDatabase;

    private object $sms;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);

        Http::fake();

        $this->sms = new class implements Sms
        {
            /** @var list<array{phone: string, text: string}> */
            public array $sent = [];

            public function send(string $phone, string $text): void
            {
                $this->sent[] = ['phone' => $phone, 'text' => $text];
            }
        };

        $this->app->instance(Sms::class, $this->sms);
    }

    /** Клієнт, який орендував і зник. */
    private function lapsed(int $daysAgo = 120, string $phone = '380672458080'): Client
    {
        $client = Client::create(['phone' => $phone]);

        Booking::create([
            'number' => 'BUR-WB-'.$client->id,
            'client_id' => $client->id,
            'branch_id' => Branch::first()->id,
            'client_type' => 'person',
            'phone' => '+380 67 245 80 80',
            'fulfilment' => 'self',
            'payment' => 'card',
            'deposit_way' => 'card-hold',
            'status' => 'closed',
            'date_from' => Carbon::today()->subDays($daysAgo + 2),
            'date_to' => Carbon::today()->subDays($daysAgo),
            'rent_total' => 500,
        ]);

        return $client->fresh();
    }

    public function test_lapsed_client_is_reminded(): void
    {
        $client = $this->lapsed();

        $this->assertSame(1, app(WinBack::class)->send());
        $this->assertSame($client->phone, $this->sms->sent[0]['phone']);
        $this->assertNotNull($client->fresh()->win_back_sent_at);
    }

    public function test_recent_client_is_left_alone(): void
    {
        // Місяць — це не пауза, а нормальний ритм. Писати таким означає
        // нагадувати тому, хто й так пам'ятає.
        $this->lapsed(daysAgo: 30);

        $this->assertSame(0, app(WinBack::class)->send());
    }

    public function test_client_with_a_rental_in_hand_is_not_written_to(): void
    {
        $client = $this->lapsed();

        Booking::create([
            'number' => 'BUR-WB-ACTIVE',
            'client_id' => $client->id,
            'branch_id' => Branch::first()->id,
            'client_type' => 'person',
            'phone' => '+380 67 245 80 80',
            'fulfilment' => 'self',
            'payment' => 'card',
            'deposit_way' => 'card-hold',
            'status' => 'issued',
            'date_from' => Carbon::today(),
            'date_to' => Carbon::today()->addDays(3),
            'rent_total' => 500,
        ]);

        // «Давно не бачились» тому, у кого зараз наш перфоратор на руках.
        $this->assertSame(0, app(WinBack::class)->send());
    }

    public function test_opt_out_is_respected(): void
    {
        $this->lapsed()->update(['marketing_opt_out' => true]);

        $this->assertSame(0, app(WinBack::class)->send());
        $this->assertSame([], $this->sms->sent);
    }

    public function test_nobody_gets_two_letters_in_a_row(): void
    {
        $this->lapsed();

        app(WinBack::class)->send();
        app(WinBack::class)->send();

        // Друге «ми скучили» підряд читається як спам.
        $this->assertCount(1, $this->sms->sent);
    }

    public function test_cooldown_expires(): void
    {
        $client = $this->lapsed();

        app(WinBack::class)->send();

        Carbon::setTestNow(now()->addDays(config('winback.cooldown_days') + 1));
        $client->fresh()->bookings()->update(['date_to' => now()->subDays(200)]);

        $this->assertSame(1, app(WinBack::class)->send());

        Carbon::setTestNow();
    }

    public function test_client_without_a_completed_rental_is_not_a_win_back(): void
    {
        // Це вже не повернення, а холодна розсилка людині, яка нічого не брала.
        Client::create(['phone' => '380991112233']);

        $this->assertSame(0, app(WinBack::class)->send());
    }

    public function test_batch_is_capped(): void
    {
        config(['winback.batch' => 2]);

        foreach (['380670000001', '380670000002', '380670000003'] as $phone) {
            $this->lapsed(phone: $phone);
        }

        // Кожне повідомлення — це гроші оператору; база на кілька тисяч
        // клієнтів здатна вигребти місячний бюджет за хвилину.
        $this->assertSame(2, app(WinBack::class)->send());
    }

    public function test_message_fits_one_sms_and_offers_a_way_out(): void
    {
        $client = $this->lapsed();
        $text = app(WinBack::class)->text($client);

        $this->assertLessThanOrEqual(WinBack::SMS_LIMIT, mb_strlen($text));
        $this->assertStringContainsString('СТОП', $text);
    }

    public function test_client_can_opt_out_from_the_cabinet(): void
    {
        $client = $this->lapsed();

        // Обіцянку «відмовитись» треба чимось виконувати. Кабінет — те місце,
        // де клієнт робить це сам, не дзвонячи менеджеру.
        $this->actingAs($client, 'client')->put('/cabinet/profile', [
            'name' => 'Олег',
            'marketing_opt_out' => '1',
        ])->assertRedirect();

        $this->assertTrue($client->fresh()->marketing_opt_out);
        $this->assertSame(0, app(WinBack::class)->send());
    }

    public function test_command_dry_run_sends_nothing(): void
    {
        $this->lapsed();

        $this->artisan('reminders:winback --dry')->assertSuccessful();

        $this->assertSame([], $this->sms->sent);
        $this->assertNull(Client::first()->win_back_sent_at);
    }
}
