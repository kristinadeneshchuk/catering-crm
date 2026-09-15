<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\CourierMileageLog;
use App\Models\CourierPayout;
use App\Models\CourierShiftReport;
use App\Models\PaymentClaim;
use App\Models\Transaction;
use App\Services\Couriers\CourierPayoutService;
use App\Services\Couriers\CourierReportService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\CourierShiftScenario;
use Tests\TestCase;

/**
 * Звіт курʼєра — чернетка на перевірку адміном; ЗП погоджується в CRM з
 * вибором рахунку і йде в чат оплат (docs/tz-ops-agent.md §1, §5).
 */
class CourierReportReviewTest extends TestCase
{
    use CourierShiftScenario;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpCourierWorld();
        config()->set('ops.courier_reports_require_review', true);
        config()->set('ops.payments_chat_id', '-100500');
    }

    protected function draft(): CourierShiftReport
    {
        $this->prepareShift();
        $this->sendReport();

        return CourierShiftReport::first();
    }

    // --- чернетка ------------------------------------------------------------

    public function test_a_complete_report_waits_for_review_and_moves_nothing(): void
    {
        $this->prepareShift();

        $reply = $this->sendReport();

        $this->assertStringContainsString('Прийнято на перевірку', $reply);
        $this->assertStringContainsString('140 км', $reply);

        $report = CourierShiftReport::first();
        $this->assertTrue($report->isDraft());
        $this->assertSame(0, CourierMileageLog::count(), 'пробіг не записано');
        $this->assertEquals(0, (float) $this->courier->fresh()->balance, 'баланс не рухався');
        $this->assertSame(0, PaymentClaim::where('source', PaymentClaim::SOURCE_COURIER_CASH)->count(), 'заяв про готівку ще немає');
        $this->assertSame(0, CourierPayout::count());
    }

    public function test_confirming_writes_everything_exactly_once(): void
    {
        $report  = $this->draft();
        $service = app(CourierReportService::class);

        $this->assertTrue($service->confirm($report, 1)['ok']);
        $this->assertFalse($service->confirm($report->fresh(), 1)['ok'], 'друге підтвердження нічого не робить');

        $log = CourierMileageLog::first();
        $this->assertSame(140, $log->km);
        $this->assertEqualsWithDelta($log->compensation, (float) $this->courier->fresh()->balance, 0.01, 'компенсація нарахована один раз');
        $this->assertSame(1, PaymentClaim::where('source', PaymentClaim::SOURCE_COURIER_CASH)->count());

        $report = $report->fresh();
        $this->assertTrue($report->isAccepted());
        $this->assertSame(1, (int) $report->reviewed_by);

        $payout = CourierPayout::first();
        $this->assertNotNull($payout, 'виплату дня пораховано');
        $this->assertNull($payout->tg_messages, 'щоденних кнопок у Telegram власнику більше немає');
    }

    public function test_the_admin_can_correct_numbers_before_confirming(): void
    {
        $report = $this->draft();
        $orderId = $report->parsed['cash'][0]['order_id'];

        app(CourierReportService::class)->confirm($report, 1, [
            'end_km' => 177600, 'fuel_price' => 55, 'cash' => [$orderId => 2500],
        ]);

        $log = CourierMileageLog::first();
        $this->assertSame(100, $log->km);
        $this->assertEquals(55, (float) $log->fuel_price_per_liter);
        $this->assertEquals(2500, PaymentClaim::where('source', PaymentClaim::SOURCE_COURIER_CASH)->value('amount'));
    }

    public function test_a_rejected_report_writes_nothing(): void
    {
        $report = $this->draft();

        $this->assertTrue(app(CourierReportService::class)->reject($report, 1, 'Фото не того авто'));

        $this->assertSame(CourierShiftReport::STATUS_REJECTED, $report->fresh()->status);
        $this->assertSame(0, CourierMileageLog::count());
    }

    public function test_a_reviewed_report_cannot_be_rewritten_by_the_courier(): void
    {
        $report = $this->draft();
        app(CourierReportService::class)->confirm($report, 1);

        $reply = $this->sendReport(received: '100');

        $this->assertStringContainsString('уже перевірено', $reply);
        $this->assertEquals(2600, PaymentClaim::where('source', PaymentClaim::SOURCE_COURIER_CASH)->value('amount'));
    }

    // --- позначки -------------------------------------------------------------

    public function test_too_many_km_against_the_plan_is_flagged(): void
    {
        $this->route(['distance_calc' => 60]);
        $this->shift(800, 'evening');
        $this->cashOrder();
        app(CourierReportService::class)->sendTemplate($this->courier->fresh(), $this->date, 'evening');

        $this->sendReport(); // 140 км при плані 60

        $report = CourierShiftReport::first();
        $mark   = collect($report->anomalies)->firstWhere('code', 'km_over_plan');

        $this->assertNotNull($mark);
        $this->assertSame('red', $mark['severity'], '×2,3 — червона');
        $this->assertStringContainsString('140 км при плані 60', $mark['text']);
        $this->assertSame('red', $report->worstSeverity());
    }

    public function test_a_gap_between_shifts_and_a_cash_difference_are_flagged(): void
    {
        CourierMileageLog::create([
            'employee_id' => $this->courier->id, 'date' => '2026-09-11', 'shift_slot' => 'full',
            'start_km' => 177300, 'end_km' => 177460, 'fuel_price_per_liter' => 58.9, 'fuel_consumption' => 8,
        ]);
        $this->prepareShift();

        $this->sendReport(received: '2500'); // старт 177500 при фініші 177460, готівка 2500 замість 2600

        $codes = collect(CourierShiftReport::first()->anomalies)->pluck('code');
        $this->assertContains('start_gap', $codes);
        $this->assertContains('cash_diff', $codes);
    }

    public function test_miles_are_shown_in_km(): void
    {
        $this->courier->update(['mileage_unit' => 'mi']);
        $report = $this->draft();

        $summary = $report->fresh()->mileageSummary();
        $this->assertSame(140, $summary['raw']);
        $this->assertSame(224, $summary['km'], '140 mi × 1,6');
        $this->assertContains('miles', collect($report->anomalies)->pluck('code'));
    }

    // --- ЗП погоджена → чат оплат --------------------------------------------

    protected function confirmedPayout(): CourierPayout
    {
        $report = $this->draft();
        app(CourierReportService::class)->confirm($report, 1);

        return CourierPayout::first();
    }

    public function test_pay_is_approved_from_the_chosen_account_and_goes_to_the_payments_chat(): void
    {
        $this->courier->update(['payout_card' => '5375 4141 2222 3333']);
        $fop     = Account::create(['name' => 'ФОП Горенко П.', 'type' => 'card']);
        $payout  = $this->confirmedPayout();
        $before  = (float) $this->courier->fresh()->balance;

        $res = app(CourierPayoutService::class)->approveAndPay($payout, $fop->id, 500, 1);

        $this->assertTrue($res['ok']);
        $this->assertTrue($res['sent']);

        $payout = $payout->fresh();
        $this->assertSame(CourierPayout::STATUS_PAID, $payout->status);
        $this->assertSame($fop->id, (int) $payout->account_id);

        $tx = Transaction::find($payout->transaction_id);
        $this->assertSame('Виплата ЗП', $tx->category);
        $this->assertSame($fop->id, (int) $tx->account_id);
        $this->assertEqualsWithDelta($before - 500, (float) $this->courier->fresh()->balance, 0.01);

        Http::assertSent(fn ($r) => str_contains($r->url(), 'sendMessage')
            && $r['chat_id'] === '-100500'
            && str_contains($r['text'], '5375 4141 2222 3333')
            && str_contains($r['text'], 'Пальне: 140 км')
            && str_contains($r['text'], 'Амортизація: 140 км')
            && str_contains($r['text'], 'ФОП Горенко П.')
            && ! isset($r['reply_markup']));
    }

    public function test_pay_cannot_be_approved_while_the_report_is_a_draft(): void
    {
        $this->draft();
        $payout = app(CourierPayoutService::class)->refresh($this->courier->fresh(), $this->date);
        $cash   = Account::create(['name' => 'Готівка', 'type' => 'cash']);

        $res = app(CourierPayoutService::class)->approveAndPay($payout, $cash->id, null, 1);

        $this->assertFalse($res['ok']);
        $this->assertStringContainsString('звіт', $res['error']);
        $this->assertSame(0, Transaction::where('category', 'Виплата ЗП')->count());
    }

    public function test_a_mistaken_payment_can_be_cancelled(): void
    {
        $payout = $this->confirmedPayout();
        $cash   = Account::create(['name' => 'Готівка', 'type' => 'cash']);
        $before = (float) $this->courier->fresh()->balance;

        $service = app(CourierPayoutService::class);
        $service->approveAndPay($payout, $cash->id, 300, 1);
        $this->assertTrue($service->cancelPayment($payout->fresh()));

        $this->assertSame(0, Transaction::where('category', 'Виплата ЗП')->count());
        $this->assertEqualsWithDelta($before, (float) $this->courier->fresh()->balance, 0.01, 'борг повернувся');
        $this->assertSame(CourierPayout::STATUS_DRAFT, $payout->fresh()->status);

        Http::assertSent(fn ($r) => str_contains($r->url(), 'editMessageText') && str_contains($r['text'], 'Скасовано'));
    }

    public function test_the_card_number_is_not_kept_in_the_message_record(): void
    {
        $this->courier->update(['payout_card' => '5375 4141 2222 3333']);
        $payout = $this->confirmedPayout();
        $cash   = Account::create(['name' => 'Готівка', 'type' => 'cash']);

        app(CourierPayoutService::class)->approveAndPay($payout, $cash->id, 100, 1);

        $raw = DB::table('courier_payouts')->where('id', $payout->id)->value('payment_message');
        $this->assertStringNotContainsString('3333', (string) $raw);
        $this->assertNotEmpty(json_decode($raw, true)['message_id']);
    }

    public function test_the_card_is_stored_encrypted_and_shown_masked(): void
    {
        $this->courier->update(['payout_card' => '5375 4141 2222 3333']);

        $raw = DB::table('employees')->where('id', $this->courier->id)->value('payout_card');
        $this->assertStringNotContainsString('3333', (string) $raw);
        $this->assertSame('•••• 3333', $this->courier->fresh()->maskedPayoutCard());
        $this->assertArrayNotHasKey('payout_card', $this->courier->fresh()->toArray());
    }

    public function test_a_day_without_bot_reports_can_still_be_counted(): void
    {
        $this->route();
        $this->shift(800, 'evening');
        $this->mileage(177500, 177560, 58.9);

        $this->assertSame(1, app(CourierPayoutService::class)->refreshDay($this->date));
        $this->assertSame(1, CourierPayout::count());
    }
}
