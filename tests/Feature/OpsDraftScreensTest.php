<?php

namespace Tests\Feature;

use App\Filament\Resources\CourierPayoutResource\Pages\ListCourierPayouts;
use App\Filament\Resources\CourierShiftReportResource\Pages\ListCourierShiftReports;
use App\Models\Account;
use App\Models\CourierPayout;
use App\Models\CourierShiftReport;
use App\Models\User;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Support\CourierShiftScenario;
use Tests\TestCase;

/**
 * Екрани чернеток у CRM: список звітів на перевірці і погодження ЗП.
 * Кнопки, що рухають гроші й пробіг, бачить лише адмін.
 */
class OpsDraftScreensTest extends TestCase
{
    use CourierShiftScenario;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpCourierWorld();
        config()->set('ops.courier_reports_require_review', true);
        config()->set('ops.payments_chat_id', '-100500');
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $this->prepareShift();
        $this->sendReport();
    }

    protected function user(string $role): User
    {
        return User::create(['name' => $role, 'email' => $role.'@t.test', 'password' => 'x', 'role' => $role]);
    }

    public function test_the_admin_sees_the_draft_and_confirms_it_from_the_list(): void
    {
        $this->actingAs($this->user('admin'));
        $report = CourierShiftReport::first();

        Livewire::test(ListCourierShiftReports::class)
            ->assertCanSeeTableRecords([$report])
            ->assertTableActionVisible('review', $report)
            ->callTableAction('review', $report, ['start_km' => 177500, 'end_km' => 177620, 'fuel_price' => 58.9])
            ->assertHasNoTableActionErrors();

        $this->assertTrue($report->fresh()->isAccepted());
        $this->assertSame(120, \App\Models\CourierMileageLog::first()->km);
    }

    public function test_a_manager_sees_the_list_but_cannot_confirm_or_reject(): void
    {
        $this->actingAs($this->user('manager'));
        $report = CourierShiftReport::first();

        Livewire::test(ListCourierShiftReports::class)
            ->assertCanSeeTableRecords([$report])
            ->assertTableActionHidden('reject', $report);

        $this->assertTrue($report->fresh()->isDraft());
    }

    public function test_the_admin_approves_pay_with_an_account_from_the_payouts_list(): void
    {
        $admin = $this->user('admin');
        $this->actingAs($admin);
        app(\App\Services\Couriers\CourierReportService::class)->confirm(CourierShiftReport::first(), $admin->id);
        $payout = CourierPayout::first();
        $fop    = Account::create(['name' => 'ФОП Горенко П.', 'type' => 'card']);

        Livewire::test(ListCourierPayouts::class)
            ->assertTableActionVisible('approve', $payout)
            ->callTableAction('approve', $payout, ['account_id' => $fop->id, 'amount' => 700])
            ->assertHasNoTableActionErrors();

        $payout = $payout->fresh();
        $this->assertSame(CourierPayout::STATUS_PAID, $payout->status);
        $this->assertEquals(700, (float) $payout->paid_amount);
        $this->assertSame($admin->id, (int) $payout->approved_by);
    }

    public function test_a_manager_cannot_approve_pay(): void
    {
        $this->actingAs($this->user('manager'));
        app(\App\Services\Couriers\CourierReportService::class)->confirm(CourierShiftReport::first(), null);

        Livewire::test(ListCourierPayouts::class)
            ->assertTableActionHidden('approve', CourierPayout::first());
    }
}
