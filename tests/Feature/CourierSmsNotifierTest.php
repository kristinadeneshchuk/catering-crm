<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\SmsLog;
use App\Services\CourierSmsNotifier;
use App\Services\ScheduleService;
use App\Services\TurboSmsService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Tests\Support\BuildsSmsTestSchema;
use Tests\TestCase;

/**
 * Наскрізна перевірка сповіщень клієнтів про курʼєра.
 *
 * Схему піднімаємо точково: повний ланцюжок міграцій проєкту на чистій БД
 * не проходить (legacy-конфлікт is_paid в orders), тож створюємо тільки те,
 * що реально читає CourierSmsNotifier. Міграцію sms_logs беремо справжню.
 */
class CourierSmsNotifierTest extends TestCase
{
    use BuildsSmsTestSchema;

    protected string $deliveryDate = '2026-08-05'; // середа

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildSmsSchema();

        // Прибираємо вихідні курʼєрів, щоб дата доставки рахувалась прямолінійно.
        Setting::create(['key' => ScheduleService::CLOSED_SLOTS_KEY, 'value' => '[]']);
        ScheduleService::clearClosedSlotsCache();

        Setting::create(['key' => TurboSmsService::KEY_TOKEN, 'value' => 'test-token']);
        Setting::create(['key' => TurboSmsService::KEY_SENDER, 'value' => 'UFIT']);
    }

    private function fakeTurboOk(): void
    {
        Http::fake(function ($request) {
            $recipients = $request->data()['recipients'] ?? [];

            return Http::response([
                'response_code'   => 0,
                'response_status' => 'OK',
                'response_result' => array_map(fn ($p) => [
                    'phone'           => $p,
                    'message_id'      => 'msg-' . $p,
                    'response_code'   => 0,
                    'response_status' => 'OK',
                ], $recipients),
            ]);
        });
    }

    private function notifier(): CourierSmsNotifier
    {
        return app(CourierSmsNotifier::class);
    }

    // -------------------------------------------------------------------------
    // ТЗ п.3 — умови активації кнопки
    // -------------------------------------------------------------------------

    public function test_button_is_blocked_until_routes_are_built(): void
    {
        $r = $this->notifier()->readiness($this->deliveryDate);

        $this->assertFalse($r['ready']);
        $this->assertStringContainsString('Маршрути ще не побудовані', $r['reason']);
    }

    public function test_button_is_blocked_when_a_route_has_no_courier(): void
    {
        $this->makeRoute(['employee_id' => null]);

        $r = $this->notifier()->readiness($this->deliveryDate);

        $this->assertFalse($r['ready']);
        $this->assertSame(1, $r['routes_without_courier']);
        $this->assertStringContainsString('Не призначено курʼєра', $r['reason']);
    }

    public function test_button_is_blocked_when_turbosms_not_configured(): void
    {
        Setting::where('key', TurboSmsService::KEY_TOKEN)->delete();
        $this->makeRoute(['employee_id' => $this->makeCourier('Іванов І.І.')]);

        $r = $this->notifier()->readiness($this->deliveryDate);

        $this->assertFalse($r['ready']);
        $this->assertStringContainsString('токен', $r['reason']);
    }

    public function test_button_becomes_ready_when_everything_is_in_place(): void
    {
        $this->makeRoute(['employee_id' => $this->makeCourier('Іванов І.І.')]);

        $r = $this->notifier()->readiness($this->deliveryDate);

        $this->assertTrue($r['ready'], $r['reason'] ?? '');
        $this->assertSame(1, $r['routes']);
    }

    // -------------------------------------------------------------------------
    // ТЗ п.4, 6, 7 — текст SMS, відправка, лог
    // -------------------------------------------------------------------------

    public function test_sends_sms_with_courier_name_phone_and_car(): void
    {
        $this->makeRoute([
            'employee_id'         => $this->makeCourier('Іванов І.І.', '0671112233'),
            'registration_number' => 'AA0000AA',
        ]);
        $ids = $this->makeOrderDay();
        $this->fakeTurboOk();

        $result = $this->notifier()->send($this->deliveryDate);

        $this->assertSame(1, $result['sent']);
        $this->assertSame(0, $result['failed']);
        $this->assertEmpty($result['problems']);

        Http::assertSent(function ($request) {
            $body = $request->data();

            return $request->url() === 'https://api.turbosms.ua/message/send.json'
                && $body['recipients'] === ['380501234567']
                && $body['sms']['sender'] === 'UFIT'
                && str_contains($body['sms']['text'], 'Іванов І.І.')
                && str_contains($body['sms']['text'], '+380671112233')
                && str_contains($body['sms']['text'], 'AA0000AA');
        });

        // ТЗ п.7 — журнал з усіма полями
        $log = SmsLog::first();
        $this->assertSame(SmsLog::STATUS_SENT, $log->status);
        $this->assertSame('380501234567', $log->phone);
        $this->assertSame('Клієнт Тест', $log->client_name);
        $this->assertSame($ids['order_id'], $log->order_id);
        $this->assertSame('Іванов І.І.', $log->courier_name);
        $this->assertSame('380671112233', $log->courier_phone);
        $this->assertSame('AA0000AA', $log->car_number);
        $this->assertSame('msg-380501234567', $log->message_id);
        $this->assertSame(0, $log->response_code);
        $this->assertNotNull($log->created_at);
        $this->assertStringContainsString('Іванов', $log->text);
    }

    public function test_sms_text_is_editable_from_settings(): void
    {
        Setting::create([
            'key'   => TurboSmsService::KEY_TEMPLATE,
            'value' => 'Вітаємо, {client}! Курʼєр {courier}, {phone}, авто {car}.',
        ]);

        $this->makeRoute(['employee_id' => $this->makeCourier('Петренко П.П.', '0931112233')]);
        $this->makeOrderDay(['name' => 'Оксана']);
        $this->fakeTurboOk();

        $this->notifier()->send($this->deliveryDate);

        $this->assertSame(
            'Вітаємо, Оксана! Курʼєр Петренко П.П., +380931112233, авто AA0000AA.',
            SmsLog::first()->text,
        );
    }

    // -------------------------------------------------------------------------
    // ТЗ п.9 — валідація, проблемні замовлення
    // -------------------------------------------------------------------------

    public static function invalidDataProvider(): array
    {
        return [
            'клієнт без телефону'      => [['phone' => null], [], [], 'не вказано телефон'],
            'некоректний телефон'      => [['phone' => '12345'], [], [], 'Некоректний номер'],
            'замовлення без маршруту'  => [[], [], ['ant_route_num' => null], 'не привʼязане до маршруту'],
            'невідомий маршрут'        => [[], [], ['ant_route_num' => 77, 'ant_driver' => 'Хтось'], 'однозначно визначити маршрут'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidDataProvider')]
    public function test_invalid_orders_are_reported_and_not_sent(array $client, array $order, array $day, string $expected): void
    {
        $this->makeRoute(['employee_id' => $this->makeCourier('Іванов І.І.')]);
        $this->makeOrderDay($client, $order, $day);
        $this->fakeTurboOk();

        $result = $this->notifier()->send($this->deliveryDate);

        $this->assertSame(0, $result['sent']);
        $this->assertCount(1, $result['problems']);
        $this->assertStringContainsString($expected, $result['problems'][0]['reason']);
        Http::assertNothingSent();
    }

    public function test_missing_courier_phone_or_car_is_reported(): void
    {
        $this->makeRoute([
            'employee_id'         => $this->makeCourier('Іванов І.І.', null),
            'registration_number' => 'AA0000AA',
        ]);
        $this->makeOrderDay();
        $this->fakeTurboOk();

        $result = $this->notifier()->send($this->deliveryDate);

        $this->assertSame(0, $result['sent']);
        $this->assertStringContainsString('не вказано (або некоректний) телефон', $result['problems'][0]['reason']);
    }

    public function test_valid_orders_are_sent_even_when_others_are_broken(): void
    {
        $this->makeRoute(['employee_id' => $this->makeCourier('Іванов І.І.')]);
        $this->makeOrderDay(['name' => 'Добрий', 'phone' => '0501234567']);
        $this->makeOrderDay(['name' => 'Без телефону', 'phone' => null]);
        $this->fakeTurboOk();

        $result = $this->notifier()->send($this->deliveryDate);

        $this->assertSame(1, $result['sent']);
        $this->assertCount(1, $result['problems']);
    }

    // -------------------------------------------------------------------------
    // Критичний кейс: ранковий і вечірній маршрут №1 у того самого курʼєра
    // -------------------------------------------------------------------------

    public function test_morning_and_evening_route_number_one_do_not_mix_up_cars(): void
    {
        $courier = $this->makeCourier('Іванов І.І.', '0671112233');

        $this->makeRoute([
            'employee_id' => $courier, 'ant_route_num' => 1,
            'registration_number' => 'AA1111AA', 'route_time_b' => '05.08.2026 09:00',
        ]);
        $this->makeRoute([
            'employee_id' => $courier, 'ant_route_num' => 1,
            'registration_number' => 'BB2222BB', 'route_time_b' => '05.08.2026 18:00',
        ]);

        // Ранковий клієнт: їжа в день доставки.
        $this->makeOrderDay(
            ['name' => 'Ранковий', 'phone' => '0501111111'],
            ['schedule_type' => 'every_day_morning'],
            ['date' => $this->deliveryDate],
        );

        // Вечірній клієнт: їжа наступного дня, доставка — сьогодні ввечері.
        $this->makeOrderDay(
            ['name' => 'Вечірній', 'phone' => '0502222222'],
            ['schedule_type' => 'every_day_evening'],
            ['date' => '2026-08-06'],
        );

        $this->fakeTurboOk();

        $result = $this->notifier()->send($this->deliveryDate);

        $this->assertSame(2, $result['sent'], 'обидва клієнти мають отримати SMS');
        $this->assertEmpty($result['problems']);

        $morning = SmsLog::where('phone', '380501111111')->first();
        $evening = SmsLog::where('phone', '380502222222')->first();

        $this->assertSame('AA1111AA', $morning->car_number, 'ранковому клієнту — ранкове авто');
        $this->assertSame('BB2222BB', $evening->car_number, 'вечірньому клієнту — вечірнє авто');
    }

    // -------------------------------------------------------------------------
    // ТЗ п.8 — захист від повторної відправки
    // -------------------------------------------------------------------------

    public function test_second_send_does_not_duplicate_sms(): void
    {
        $this->makeRoute(['employee_id' => $this->makeCourier('Іванов І.І.')]);
        $this->makeOrderDay();
        $this->fakeTurboOk();

        $first = $this->notifier()->send($this->deliveryDate);
        $this->assertSame(1, $first['sent']);

        $second = $this->notifier()->send($this->deliveryDate);

        $this->assertSame(0, $second['sent'], 'повторна відправка не має дублювати SMS');
        $this->assertSame(1, $second['skipped']);
        $this->assertSame(1, SmsLog::count());
    }

    public function test_changed_route_allows_resend_with_updated_data(): void
    {
        $courierId = $this->makeCourier('Іванов І.І.');
        $routeId   = $this->makeRoute(['employee_id' => $courierId]);
        $this->makeOrderDay();
        $this->fakeTurboOk();

        $this->notifier()->send($this->deliveryDate);

        // Маршрути перебудували: інший курʼєр і авто.
        $newCourier = $this->makeCourier('Петренко П.П.', '0997778899');
        DB::table('delivery_routes')->where('id', $routeId)->update([
            'employee_id' => $newCourier, 'driver_name' => 'Петренко П.П.',
            'registration_number' => 'CC3333CC',
        ]);
        DB::table('order_days')->update(['ant_driver' => 'Петренко П.П.']);

        $second = $this->notifier()->send($this->deliveryDate);

        $this->assertSame(1, $second['sent'], 'після зміни маршруту клієнт має отримати оновлену SMS');
        $this->assertSame('CC3333CC', SmsLog::latest('id')->first()->car_number);
    }

    public function test_force_resend_sends_to_everyone_again(): void
    {
        $this->makeRoute(['employee_id' => $this->makeCourier('Іванов І.І.')]);
        $this->makeOrderDay();
        $this->fakeTurboOk();

        $this->notifier()->send($this->deliveryDate);
        $again = $this->notifier()->send($this->deliveryDate, 'all', resendAll: true);

        $this->assertSame(1, $again['sent']);
        $this->assertSame(2, SmsLog::count());
    }

    // -------------------------------------------------------------------------
    // ТЗ п.5(4) — обробка відповідей TurboSMS
    // -------------------------------------------------------------------------

    public function test_insufficient_funds_is_reported_and_logged_as_failed(): void
    {
        $this->makeRoute(['employee_id' => $this->makeCourier('Іванов І.І.')]);
        $this->makeOrderDay();

        Http::fake([
            '*' => Http::response(['response_code' => 203, 'response_status' => 'REQUIRED_BALANCE']),
        ]);

        $result = $this->notifier()->send($this->deliveryDate);

        $this->assertSame(0, $result['sent']);
        $this->assertSame(1, $result['failed']);
        $this->assertStringContainsString('Недостатньо коштів', $result['errors'][0]);
        $this->assertSame(SmsLog::STATUS_FAILED, SmsLog::first()->status);
    }

    public function test_partial_success_marks_only_the_rejected_number_as_failed(): void
    {
        $this->makeRoute(['employee_id' => $this->makeCourier('Іванов І.І.')]);
        $this->makeOrderDay(['name' => 'Добрий', 'phone' => '0501111111']);
        $this->makeOrderDay(['name' => 'Поганий', 'phone' => '0502222222']);

        Http::fake([
            '*' => Http::response([
                'response_code'   => 802,
                'response_status' => 'SUCCESS_MESSAGE_PARTIAL_ACCEPTED',
                'response_result' => [
                    ['phone' => '380501111111', 'message_id' => 'ok-1', 'response_code' => 0,   'response_status' => 'OK'],
                    ['phone' => '380502222222', 'message_id' => null,   'response_code' => 305, 'response_status' => 'INVALID_PHONE'],
                ],
            ]),
        ]);

        $result = $this->notifier()->send($this->deliveryDate);

        $this->assertSame(1, $result['sent']);
        $this->assertSame(1, $result['failed']);
        $this->assertStringContainsString('Некоректний номер телефону', $result['errors'][0]);

        $this->assertSame(SmsLog::STATUS_SENT, SmsLog::where('phone', '380501111111')->first()->status);
        $this->assertSame(SmsLog::STATUS_FAILED, SmsLog::where('phone', '380502222222')->first()->status);
    }

    public function test_api_transport_error_does_not_mark_anything_as_sent(): void
    {
        $this->makeRoute(['employee_id' => $this->makeCourier('Іванов І.І.')]);
        $this->makeOrderDay();

        // Http::fake() ДОПИСУЄ заглушки, а не замінює, тому послідовність —
        // єдиний спосіб віддати різні відповіді на два виклики поспіль.
        Http::fakeSequence()
            ->push('gateway down', 500)
            ->push([
                'response_code'   => 0,
                'response_status' => 'OK',
                'response_result' => [[
                    'phone' => '380501234567', 'message_id' => 'retry-ok',
                    'response_code' => 0, 'response_status' => 'OK',
                ]],
            ], 200);

        $result = $this->notifier()->send($this->deliveryDate);

        $this->assertSame(0, $result['sent']);
        $this->assertSame(1, $result['failed']);
        $this->assertSame(SmsLog::STATUS_FAILED, SmsLog::first()->status);

        // Після збою повторна відправка має бути дозволена — невдала спроба
        // не повинна рахуватись як «вже надіслано».
        $retry = $this->notifier()->send($this->deliveryDate);

        $this->assertSame(1, $retry['sent'], 'після помилки клієнт має отримати SMS з повторної спроби');
        $this->assertSame(0, $retry['skipped']);
        $this->assertSame('retry-ok', SmsLog::latest('id')->first()->message_id);
    }

    public function test_code_801_is_treated_as_success_not_as_error(): void
    {
        $this->makeRoute(['employee_id' => $this->makeCourier('Іванов І.І.')]);
        $this->makeOrderDay();

        // 801 = SUCCESS_MESSAGE_SENT. Якби ми вважали його помилкою, клієнт
        // отримав би дубль при наступній відправці.
        Http::fake([
            '*' => Http::response([
                'response_code'   => 801,
                'response_status' => 'SUCCESS_MESSAGE_SENT',
                'response_result' => [[
                    'phone' => '380501234567', 'message_id' => 'm-801',
                    'response_code' => 801, 'response_status' => 'SUCCESS_MESSAGE_SENT',
                ]],
            ]),
        ]);

        $result = $this->notifier()->send($this->deliveryDate);

        $this->assertSame(1, $result['sent']);
        $this->assertSame(0, $result['failed']);
        $this->assertSame(SmsLog::STATUS_SENT, SmsLog::first()->status);

        // І повторна відправка не має нічого дублювати.
        $this->assertSame(0, $this->notifier()->send($this->deliveryDate)['sent']);
    }

    public function test_global_failure_is_logged_with_code_and_raw_response(): void
    {
        $this->makeRoute(['employee_id' => $this->makeCourier('Іванов І.І.')]);
        $this->makeOrderDay();

        Http::fake([
            '*' => Http::response(['response_code' => 203, 'response_status' => 'REQUIRED_BALANCE']),
        ]);

        $this->notifier()->send($this->deliveryDate);

        $log = SmsLog::first();
        $this->assertSame(203, $log->response_code, 'код відмови має потрапити в журнал');
        $this->assertSame('REQUIRED_BALANCE', $log->response_status);
        $this->assertStringContainsString('REQUIRED_BALANCE', $log->response_body);
    }

    public function test_same_courier_on_two_routes_produces_one_sms_and_one_log(): void
    {
        $courier = $this->makeCourier('Іванов І.І.', '0671112233');

        // Два маршрути з різними номерами, але тим самим курʼєром і авто —
        // для клієнта це одна й та сама інформація.
        $this->makeRoute([
            'employee_id' => $courier, 'ant_route_num' => 1,
            'registration_number' => 'AA1111AA', 'route_time_b' => '05.08.2026 09:00',
        ]);
        $this->makeRoute([
            'employee_id' => $courier, 'ant_route_num' => 2,
            'registration_number' => 'AA1111AA', 'route_time_b' => '05.08.2026 18:00',
        ]);

        $ids = $this->makeOrderDay([], [], ['ant_route_num' => 1]);
        DB::table('order_days')->insert([
            'order_id' => $ids['order_id'], 'date' => $this->deliveryDate,
            'ant_route_num' => 2, 'ant_driver' => 'Іванов І.І.',
        ]);

        $this->fakeTurboOk();

        $result = $this->notifier()->send($this->deliveryDate);

        $this->assertSame(1, $result['sent'], 'однакова інформація — одна SMS');
        $this->assertSame(1, SmsLog::count(), 'і рівно один рядок у журналі');
    }

    // -------------------------------------------------------------------------
    // Подвійний раціон: один клієнт, кілька днів в одній доставці
    // -------------------------------------------------------------------------

    public function test_client_with_two_days_on_same_route_gets_one_sms(): void
    {
        $this->makeRoute(['employee_id' => $this->makeCourier('Іванов І.І.')]);

        $ids = $this->makeOrderDay();
        // Другий день того самого замовлення (подвійний раціон).
        DB::table('order_days')->insert([
            'order_id' => $ids['order_id'], 'date' => $this->deliveryDate,
            'ant_route_num' => 1, 'ant_driver' => 'Іванов І.І.',
        ]);

        $this->fakeTurboOk();

        $result = $this->notifier()->send($this->deliveryDate);

        $this->assertSame(1, $result['sent'], 'один клієнт — одна SMS');
        $this->assertSame(1, SmsLog::count());
    }
}
