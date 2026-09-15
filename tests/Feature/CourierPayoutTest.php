<?php

namespace Tests\Feature;

use App\Models\CourierMileageLog;
use App\Models\CourierPayout;
use App\Models\CourierShiftReport;
use App\Models\Employee;
use App\Models\EmployeeBonus;
use App\Models\EmployeePenalty;
use App\Models\EmployeeShift;
use App\Models\Order;
use App\Models\PaymentClaim;
use App\Services\Couriers\CourierPayoutCalculator;
use App\Services\Couriers\CourierPayoutService;
use App\Services\Couriers\CourierReportService;
use App\Services\Payments\PaymentClaimService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\CourierShiftScenario;
use Tests\TestCase;

/**
 * Виплата курʼєру з погодженням у Telegram.
 *
 * Нарахування вже живе в employees.balance за чинними правилами. Тут — шар
 * звіту від курʼєра і погодження власником. Головна перевірка: розклад у
 * Telegram дорівнює рівно тому, що за цей день рахують «Зарплати».
 */
class CourierPayoutTest extends TestCase
{
    use CourierShiftScenario;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpCourierWorld();

        // Ці тести — про старий шлях: звіт одразу пишеться в систему.
        // Перевірка адміном — у CourierReportReviewTest.
        config()->set('ops.courier_reports_require_review', false);
    }

    protected function calc(): array
    {
        return app(CourierPayoutCalculator::class)->calculate($this->courier->fresh(), $this->date);
    }

    /** Те, що за цей день складає «Зарплати»: ставка + компенсація + премії − штрафи. */
    protected function payrollDaySum(): float
    {
        return (float) EmployeeShift::where('employee_id', $this->courier->id)->where('is_planned', false)->sum('rate')
            + CourierMileageLog::where('employee_id', $this->courier->id)->get()->sum->compensation
            + (float) EmployeeBonus::where('employee_id', $this->courier->id)->sum('amount')
            - (float) EmployeePenalty::where('employee_id', $this->courier->id)->sum('amount');
    }

    // --- калькулятор: головна перевірка ТЗ ----------------------------------

    public function test_the_total_equals_the_existing_components_on_the_same_data(): void
    {
        // 15 точок: 3 понад ліміт × 50 = 150. Два виїзди × 800 = 1600.
        $this->route(['count_comps' => 15]);
        $this->shift(1750);
        $this->mileage(177500, 177640, 58.9); // 140 км
        EmployeeBonus::create(['employee_id' => $this->courier->id, 'amount' => 200, 'reason' => 'Субота', 'date' => $this->date]);
        EmployeePenalty::create(['employee_id' => $this->courier->id, 'amount' => 100, 'reason' => 'Запізнення', 'date' => $this->date]);

        $calc = $this->calc();

        $this->assertEqualsWithDelta($this->payrollDaySum(), $calc['total'], 0.001,
            'розклад у Telegram має дорівнювати тому, що рахують «Зарплати»');

        $c = $calc['components'];
        $this->assertSame(2, $c['trips']);
        $this->assertEquals(1600, $c['base']);
        $this->assertEquals(150, $c['routes'][0]['extra_stops_amount']);
        $this->assertEquals(0, $c['adjustment'], 'ставка в Табелі збігається з розрахунком за чинними правилами');

        // 140 км × 8 л/100 × 58.9 = 659.68; амортизація 140 × 1.
        $this->assertEqualsWithDelta(659.68, $c['mileage'][0]['fuel_cost'], 0.01);
        $this->assertEquals(140, $c['mileage'][0]['amortization']);
    }

    public function test_a_single_shift_is_one_trip(): void
    {
        // Слот «Вечір» з Табеля — один виїзд, навіть якщо is_half = false.
        $this->route();
        $this->shift(800, 'evening');

        $calc = $this->calc();

        $this->assertSame(1, $calc['components']['trips']);
        $this->assertEquals(0, $calc['components']['adjustment']);
    }

    public function test_a_hand_edited_rate_shows_as_an_adjustment_not_a_different_total(): void
    {
        // Менеджер поправив ставку руками. Підсумок має збігтися з балансом,
        // тож різницю показуємо окремим рядком.
        $this->route();
        $this->shift(1900);

        $calc = $this->calc();

        $this->assertEquals(300, $calc['components']['adjustment']);
        $this->assertEqualsWithDelta($this->payrollDaySum(), $calc['total'], 0.001);
    }

    public function test_a_planned_shift_is_not_money(): void
    {
        $this->shift(1600, 'full', planned: true);

        $this->assertEquals(0, $this->calc()['total']);
    }

    // --- розбір звіту ---------------------------------------------------------

    public function test_the_parser_is_tolerant(): void
    {
        $expected = ['cash' => [
            ['order_id' => 11, 'expected' => 2600],
            ['order_id' => 12, 'expected' => 1800],
            ['order_id' => 13, 'expected' => 900],
        ]];

        $parsed = app(CourierReportService::class)->parse(<<<'TXT'
            ЗВІТ ЗМІНИ · 12.09 · вечір · маршрут №14 (11 точок)
            Пробіг старт: 177 500
            Пробіг фініш: 177640
            Пальне, грн/л: 58,9
            Готівка від клієнтів:
            - #11 Ірина К. (Печерськ) чекаємо 2600 → отримав: +
            - #12 Олег П. (Оболонь) чекаємо 1800 → отримав: ______
            - #13 Анна С. чекаємо 900 → отримав: 850
            Коментар: не було домофону
            TXT, $expected);

        $this->assertSame(177500, $parsed['start_km']);
        $this->assertSame(177640, $parsed['end_km']);
        $this->assertEquals(58.9, $parsed['fuel_price']);
        $this->assertSame('не було домофону', $parsed['comment']);

        $cash = collect($parsed['cash'])->keyBy('order_id');
        $this->assertEquals(2600, $cash[11]['received'], '«+» — рівно очікувана сума');
        $this->assertEquals(0, $cash[12]['received'], 'порожнє — не брав');
        $this->assertEquals(850, $cash[13]['received']);
    }

    public function test_validation_names_exactly_what_is_missing(): void
    {
        $service = app(CourierReportService::class);
        $report  = new CourierShiftReport(['expected' => ['start_km' => 100, 'fuel_price' => 58, 'distance' => 0]]);

        $this->assertContains('пробіг фініш', $service->validate($report, [], ['a', 'b']));
        $this->assertContains('пробіг фініш має бути більшим за старт', $service->validate($report, ['end_km' => 90], ['a', 'b']));
        $this->assertContains('фото одометра (1 з 2)', $service->validate($report, ['end_km' => 150], ['a']));
        $this->assertSame([], $service->validate($report, ['end_km' => 150], ['a', 'b']));

        // Без плану з ANT — запасна межа. 900 км за зміну — явно помилка в цифрі.
        $problems = $service->validate($report, ['end_km' => 1000], ['a', 'b']);
        $this->assertStringContainsString('перевірте пробіг', implode(' ', $problems));
    }

    // --- шаблон і повний цикл -------------------------------------------------

    public function test_the_template_is_prefilled_with_what_the_system_knows(): void
    {
        $this->route();
        CourierMileageLog::create([
            'employee_id' => $this->courier->id, 'date' => '2026-09-11', 'shift_slot' => 'full',
            'start_km' => 177300, 'end_km' => 177500, 'fuel_price_per_liter' => 58.9, 'fuel_consumption' => 8,
        ]);
        $order = $this->cashOrder();

        $service  = app(CourierReportService::class);
        $expected = $service->expected($this->courier, $this->date, 'evening');
        $text     = $service->renderTemplate($this->courier, $this->date, 'evening', $expected);

        $this->assertStringContainsString('ЗВІТ ЗМІНИ · 12.09 · вечір · маршрут №14 (11 точок)', $text);
        $this->assertStringContainsString('Пробіг старт: 177500', $text, 'з фінішу минулої зміни');
        $this->assertStringContainsString('Пальне, грн/л: 58.9', $text);
        $this->assertStringContainsString("- #{$order->id} Ірина К. (Печерськ) чекаємо 2600 → отримав: ______", $text);
    }

    public function test_an_order_without_a_cash_sign_is_not_in_the_template(): void
    {
        $this->route();
        $this->cashOrder(method: 'transfer');

        $expected = app(CourierReportService::class)->expected($this->courier, $this->date, 'evening');

        $this->assertSame([], $expected['cash']);
    }

    public function test_a_client_cash_claim_puts_the_order_into_the_template(): void
    {
        // Спосіб оплати невідомий, але клієнт сказав агенту «передам готівкою».
        $this->route();
        $order = $this->cashOrder(method: 'transfer');
        $order->update(['payment_method' => null]);

        app(PaymentClaimService::class)->create($order, [
            'source' => PaymentClaim::SOURCE_CLIENT_CASH, 'amount' => 2400,
            'reported_by_type' => PaymentClaim::REPORTED_BY_AGENT,
        ]);

        $expected = app(CourierReportService::class)->expected($this->courier, $this->date, 'evening');

        $this->assertEquals(2400, $expected['cash'][0]['expected'], 'чекаємо стільки, скільки назвав клієнт');
    }

    public function test_a_complete_report_is_spread_through_the_system(): void
    {
        $order = $this->prepareShift();

        $reply = $this->sendReport();

        $this->assertStringContainsString('Прийнято', $reply);

        // Пробіг — через той самий сервіс, що й Логістика: баланс рухнувся на компенсацію.
        $log = CourierMileageLog::where('employee_id', $this->courier->id)->whereDate('date', $this->date)->first();
        $this->assertSame(140, $log->km);
        $this->assertEqualsWithDelta($log->compensation, (float) $this->courier->fresh()->balance, 0.01);

        // Готівка стала заявою, яка чекає менеджера. is_paid не змінився.
        $claim = PaymentClaim::where('source', PaymentClaim::SOURCE_COURIER_CASH)->first();
        $this->assertEquals(2600, $claim->amount);
        $this->assertSame($this->courier->id, $claim->employee_id);
        $this->assertTrue($claim->isPending());
        $this->assertFalse((bool) $order->fresh()->is_paid);

        // Виплату порахували і надіслали на погодження обом.
        $payout = CourierPayout::first();
        $this->assertSame(CourierPayout::STATUS_SENT, $payout->status);
        $this->assertEquals(2600, $payout->cash_on_hand);
        $this->assertEqualsWithDelta((float) $payout->total - 2600, (float) $payout->to_pay, 0.01);
        $this->assertCount(2, $payout->tg_messages);
    }

    public function test_an_incomplete_report_says_what_to_add_in_one_line(): void
    {
        $this->prepareShift();

        $reply = $this->sendReport(photos: ['ph1']);

        $this->assertSame('Майже готово. Допишіть: фото одометра (1 з 2).', $reply);
        $this->assertSame(0, CourierPayout::count(), 'на погодження не йде, поки звіт неповний');

        // Друге фото окремим повідомленням — і звіт закривається.
        $reply = app(CourierReportService::class)->ingest($this->courier->fresh(), null, ['ph2']);
        $this->assertStringContainsString('Прийнято', $reply);
    }

    public function test_a_corrected_report_updates_the_claim_instead_of_adding_one(): void
    {
        $this->prepareShift();

        $this->sendReport(received: '2600');
        $this->sendReport(received: '2500');

        $claims = PaymentClaim::where('source', PaymentClaim::SOURCE_COURIER_CASH)->get();
        $this->assertCount(1, $claims);
        $this->assertEquals(2500, $claims->first()->amount);
    }

    public function test_courier_cash_pairs_with_what_the_client_said(): void
    {
        $order = $this->prepareShift();

        app(PaymentClaimService::class)->create($order, [
            'source' => PaymentClaim::SOURCE_CLIENT_CASH, 'amount' => 2600,
            'reported_by_type' => PaymentClaim::REPORTED_BY_AGENT,
        ]);

        $this->sendReport();

        $courier = PaymentClaim::where('source', PaymentClaim::SOURCE_COURIER_CASH)->first();
        $this->assertNotNull($courier->paired_claim_id, 'звірка по клієнту і по курʼєру — в одному місці');
    }

    public function test_mileage_entered_by_the_manager_is_not_overwritten(): void
    {
        // Менеджер уже вніс пробіг за ранок і вечір окремо — звіт «за весь день»
        // поверх нього порахував би компенсацію двічі.
        $this->prepareShift();
        $this->mileage(177500, 177560, 58.9, 'morning');

        $reply = $this->sendReport();

        $this->assertStringContainsString('вніс менеджер', $reply);
        $this->assertSame(1, CourierMileageLog::where('employee_id', $this->courier->id)->count());
    }

    // --- погодження в Telegram ------------------------------------------------

    protected function sentPayout(): CourierPayout
    {
        $this->prepareShift();
        $this->sendReport();

        return CourierPayout::first();
    }

    protected function press(string $fromId, string $action, CourierPayout $payout): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/webhooks/telegram-bot', [
            'update_id' => 1,
            'callback_query' => [
                'id' => 'cb1', 'from' => ['id' => (int) $fromId],
                'data' => "payout:{$action}:{$payout->id}",
            ],
        ], ['X-Telegram-Bot-Api-Secret-Token' => 'sec']);
    }

    public function test_only_the_owner_and_senior_manager_can_approve(): void
    {
        $payout = $this->sentPayout();

        $this->press('999', 'approve', $payout)->assertOk();
        $this->assertSame(CourierPayout::STATUS_SENT, $payout->fresh()->status, 'сторонній не погодить');

        $this->press('100', 'approve', $payout)->assertOk();
        $this->assertSame(CourierPayout::STATUS_APPROVED, $payout->fresh()->status);
        $this->assertSame('100', $payout->fresh()->approved_by_tg);

        Http::assertSent(fn ($r) => str_contains($r->url(), 'editMessageText')
            && $r['reply_markup'] === ['inline_keyboard' => []]);
    }

    public function test_the_second_approver_sees_it_is_already_decided(): void
    {
        $payout = $this->sentPayout();

        $this->assertSame('Погоджено ✅', app(CourierPayoutService::class)->handleButton('100', 'approve', $payout->id));
        $this->assertStringStartsWith('Уже вирішено', app(CourierPayoutService::class)->handleButton('200', 'reject', $payout->id));
        $this->assertSame(CourierPayout::STATUS_APPROVED, $payout->fresh()->status);
    }

    public function test_an_approved_payout_is_not_recalculated(): void
    {
        $payout = $this->sentPayout();
        app(CourierPayoutService::class)->handleButton('100', 'approve', $payout->id);
        $total = (float) $payout->fresh()->total;

        EmployeeBonus::create(['employee_id' => $this->courier->id, 'amount' => 500, 'reason' => 'пізніше', 'date' => $this->date]);
        app(CourierPayoutService::class)->refresh($this->courier->fresh(), $this->date);

        $this->assertEquals($total, (float) $payout->fresh()->total, 'погоджена сума — вже рішення');
    }

    public function test_the_message_shows_the_breakdown(): void
    {
        $payout = $this->sentPayout();
        $text   = app(CourierPayoutService::class)->render($payout);

        $this->assertStringContainsString('Ставка: 800 ₴ × 1 виїзд = 800 ₴', $text);
        $this->assertStringContainsString('Пальне: 140 км × 8 л/100 × 58.90', $text);
        $this->assertStringContainsString('Готівка на руках: −2 600 ₴', $text);
        $this->assertStringContainsString('До виплати', $text);
    }

    public function test_paying_in_payroll_closes_approved_days(): void
    {
        $payout = $this->sentPayout();
        app(CourierPayoutService::class)->handleButton('100', 'approve', $payout->id);

        app(CourierPayoutService::class)->markPaid($this->courier, '2026-09-01', '2026-09-30');

        $this->assertSame(CourierPayout::STATUS_PAID, $payout->fresh()->status);
    }

    // --- вебхук бота ----------------------------------------------------------

    public function test_the_webhook_needs_its_secret(): void
    {
        $this->postJson('/webhooks/telegram-bot', ['update_id' => 1], ['X-Telegram-Bot-Api-Secret-Token' => 'wrong'])
            ->assertUnauthorized();

        config()->set('services.telegram.webhook_secret', '');
        $this->postJson('/webhooks/telegram-bot', ['update_id' => 1])->assertStatus(503);
    }

    public function test_a_courier_links_the_bot_with_a_one_time_code(): void
    {
        $courier = $this->makeCourier(['name' => 'Анна', 'telegram_chat_id' => null, 'telegram_link_code' => 'abc123']);

        $this->postJson('/webhooks/telegram-bot', ['update_id' => 2, 'message' => [
            'chat' => ['id' => 555], 'text' => '/start abc123',
        ]], ['X-Telegram-Bot-Api-Secret-Token' => 'sec'])->assertOk();

        $courier->refresh();
        $this->assertSame('555', $courier->telegram_chat_id);
        $this->assertNull($courier->telegram_link_code, 'код одноразовий');
    }

    public function test_a_report_arrives_through_the_webhook_with_a_photo(): void
    {
        $this->prepareShift();

        $this->postJson('/webhooks/telegram-bot', ['update_id' => 3, 'message' => [
            'chat' => ['id' => 777],
            'caption' => "ЗВІТ ЗМІНИ · 12.09 · вечір\nПробіг фініш: 177640",
            'photo' => [['file_id' => 'small'], ['file_id' => 'big']],
        ]], ['X-Telegram-Bot-Api-Secret-Token' => 'sec'])->assertOk();

        $report = CourierShiftReport::first();
        $this->assertSame(177640, $report->parsed['end_km']);
        $this->assertCount(1, $report->photos);
        Storage::disk('local')->assertExists($report->photos[0]);
    }
}
