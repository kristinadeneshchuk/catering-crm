<?php

namespace Tests\Feature;

use App\Filament\Pages\Payroll;
use App\Models\Employee;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsCourierTestSchema;
use Tests\TestCase;

/**
 * «Зарплати»: «До виплати» — борг саме станом на кінець обраного періоду.
 * Дні, відпрацьовані після нього, у цю суму не входять.
 */
class PayrollPeriodDueTest extends TestCase
{
    use BuildsCourierTestSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildCourierSchema();
        DB::table('positions')->insert(['key' => 'cook', 'name' => 'Кухар', 'payment_type' => 'per_shift']);
        \Illuminate\Support\Facades\Schema::create('rate_history', function ($t) {
            $t->id(); $t->string('scope'); $t->decimal('value', 10, 2); $t->date('effective_from'); $t->timestamps();
        });
    }

    private function row(string $start, string $end, Employee $emp): array
    {
        $page = new Payroll;
        $page->startDate = $start;
        $page->endDate   = $end;

        return collect($page->getData()['rows'])->firstWhere('id', $emp->id);
    }

    public function test_due_excludes_work_after_the_period_end(): void
    {
        $cook = Employee::create(['name' => 'Кухар', 'position' => 'cook', 'base_rate' => 1200, 'balance' => 0]);
        // 19–25.10 відпрацював 3 дні, 3 600 уже виплатили; 26–28.10 ще 3 дні та штраф 100.
        foreach (['2026-10-20', '2026-10-21', '2026-10-22', '2026-10-26', '2026-10-27', '2026-10-28'] as $d) {
            DB::table('employee_shifts')->insert(['employee_id' => $cook->id, 'date' => $d, 'rate' => 1200]);
        }
        DB::table('employee_shifts')->insert(['employee_id' => $cook->id, 'date' => '2026-10-29', 'rate' => 1200, 'is_planned' => true]);
        DB::table('employee_penalties')->insert(['employee_id' => $cook->id, 'date' => '2026-10-27', 'amount' => 100, 'reason' => 'тест']);
        // Баланс як на проді: 6×1200 − 100 − 1200 виплати.
        $cook->forceFill(['balance' => 5900])->saveQuietly();

        $row = $this->row('2026-10-19', '2026-10-25', $cook);

        $this->assertEquals(3600, $row['sum']);
        $this->assertEquals(5900 - (3 * 1200 - 100), $row['balance'], 'до виплати по 25.10 = борг мінус нараховане після');
        $this->assertEquals(5900, $row['balance_now']);
    }

    public function test_due_never_exceeds_the_current_debt(): void
    {
        $cook = Employee::create(['name' => 'Кухар', 'position' => 'cook', 'base_rate' => 1200, 'balance' => 0]);
        DB::table('employee_shifts')->insert(['employee_id' => $cook->id, 'date' => '2026-10-20', 'rate' => 1200]);
        DB::table('employee_penalties')->insert(['employee_id' => $cook->id, 'date' => '2026-10-27', 'amount' => 500, 'reason' => 'тест']);
        $cook->forceFill(['balance' => 700])->saveQuietly();

        $this->assertEquals(700, $this->row('2026-10-19', '2026-10-25', $cook)['balance']);
    }
}
