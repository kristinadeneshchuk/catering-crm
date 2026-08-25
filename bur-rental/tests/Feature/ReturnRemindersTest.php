<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Services\Messaging\Sms;
use App\Services\ReturnReminders;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ReturnRemindersTest extends TestCase
{
    use RefreshDatabase;

    /** Ловить усе, що пішло б у SMS. */
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

    private function bookingDueTomorrow(string $status = 'issued'): Booking
    {
        $booking = Booking::firstOrFail();

        $booking->forceFill([
            'status' => $status,
            'date_to' => today()->addDay(),
            'return_reminded_at' => null,
        ])->save();

        return $booking;
    }

    public function test_client_is_reminded_the_day_before(): void
    {
        $booking = $this->bookingDueTomorrow();

        $sent = app(ReturnReminders::class)->send();

        $this->assertSame(1, $sent);
        $this->assertSame($booking->phone, $this->sms->sent[0]['phone']);
        $this->assertStringContainsString($booking->number, $this->sms->sent[0]['text']);
    }

    public function test_reminder_goes_out_once_even_if_the_cron_runs_twice(): void
    {
        $this->bookingDueTomorrow();

        app(ReturnReminders::class)->send();
        app(ReturnReminders::class)->send();

        // Дві однакові SMS — це наші гроші і вигляд несправного сайту.
        $this->assertCount(1, $this->sms->sent);
    }

    public function test_bookings_with_other_dates_and_statuses_are_left_alone(): void
    {
        $booking = $this->bookingDueTomorrow();
        $booking->forceFill(['date_to' => today()->addDays(3)])->save();

        $this->assertSame(0, app(ReturnReminders::class)->send());

        // Закрита бронь теж не турбує клієнта: техніка вже на складі.
        $booking->forceFill(['date_to' => today()->addDay(), 'status' => 'closed'])->save();

        $this->assertSame(0, app(ReturnReminders::class)->send());
    }

    public function test_message_fits_into_one_sms(): void
    {
        $booking = $this->bookingDueTomorrow();

        $this->assertLessThanOrEqual(
            ReturnReminders::SMS_LIMIT,
            mb_strlen(app(ReturnReminders::class)->text($booking))
        );
    }

    public function test_a_long_model_name_does_not_push_the_message_into_a_second_sms(): void
    {
        $booking = $this->bookingDueTomorrow();

        $booking->items()->first()->update([
            'title' => 'Перфоратор акумуляторний безщітковий SDS-max з системою пиловидалення та кейсом',
        ]);

        $text = app(ReturnReminders::class)->text($booking->fresh('items'));

        $this->assertLessThanOrEqual(ReturnReminders::SMS_LIMIT, mb_strlen($text));
        // Замість назви — кількість позицій: номер броні клієнт знайде в кабінеті.
        $this->assertStringContainsString('поз.', $text);
    }

    public function test_command_dry_run_sends_nothing(): void
    {
        $this->bookingDueTomorrow();

        $this->artisan('reminders:returns --dry')->assertSuccessful();

        $this->assertSame([], $this->sms->sent);
        $this->assertNull(Booking::firstOrFail()->return_reminded_at);
    }

    public function test_command_sends_and_stamps(): void
    {
        $this->bookingDueTomorrow();

        $this->artisan('reminders:returns')->assertSuccessful();

        $this->assertCount(1, $this->sms->sent);
        $this->assertNotNull(Booking::firstOrFail()->return_reminded_at);
    }
}
