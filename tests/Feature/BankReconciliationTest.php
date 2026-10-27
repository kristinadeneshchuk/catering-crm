<?php

namespace Tests\Feature;

use App\Filament\Resources\BankReconciliationResource;
use App\Filament\Resources\BankReconciliationResource\Pages\ListBankReconciliation;
use App\Models\BankOperation;
use App\Models\CounterpartyRule;
use App\Models\Employee;
use App\Models\Ingredient;
use App\Models\StockDocument;
use App\Models\Supplier;
use App\Models\Transaction;
use App\Services\Accounting\BankReconciler;
use App\Services\Accounting\CashBook;
use Filament\Facades\Filament;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Tests\Support\CashWorld;
use Tests\TestCase;

/**
 * «Звірка»: операції банку розбираються в записи CRM — те, що раніше робили в Saldo.
 */
class BankReconciliationTest extends TestCase
{
    use CashWorld;

    protected BankReconciler $r;

    protected function setUp(): void
    {
        parent::setUp();

        (require database_path('migrations/2026_10_09_200000_create_bank_operations.php'))->up();
        (require database_path('migrations/2026_10_27_100000_create_bank_reconciliation.php'))->up();
        foreach (['orders', 'clients'] as $t) {
            Schema::dropIfExists($t);
        }
        Schema::create('clients', fn (Blueprint $t) => [$t->id(), $t->string('name'), $t->timestamps()]);
        Schema::create('orders', fn (Blueprint $t) => [$t->id(), $t->unsignedBigInteger('client_id')->nullable(), $t->string('status')->nullable(), $t->timestamps()]);

        config(['services.finance.reconcile_from' => '2026-10-01', 'services.finance.super_admin_ids' => '1']);
        $this->card->update(['mono_token' => 'test-token', 'mono_iban' => 'UA000000000000000000000000001']);
        $this->r = app(BankReconciler::class);
    }

    protected function op(float $amount, string $at, array $extra = []): BankOperation
    {
        static $n = 0;

        return BankOperation::create($extra + [
            'account_id' => $this->card->id, 'bank_id' => 'b'.(++$n), 'operated_at' => $at, 'amount' => $amount,
            'description' => '', 'comment' => null,
        ]);
    }

    /** Оплата клієнта, внесена менеджером (без подій моделі — баланси клієнтів тут ні до чого). */
    protected function payment(int $orderId, float $amount, string $date, string $client = 'Клієнт'): int
    {
        $cid = DB::table('clients')->insertGetId(['name' => $client]);
        DB::table('orders')->insertOrIgnore(['id' => $orderId, 'client_id' => $cid]);

        return DB::table('transactions')->insertGetId([
            'type' => 'income', 'category' => CashBook::CLIENT, 'amount' => $amount, 'date' => $date,
            'order_id' => $orderId, 'account_id' => $this->card->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_one_bank_payment_for_three_orders_is_linked_by_order_numbers(): void
    {
        $a = $this->payment(2207, 17840, '2026-10-25');
        $b = $this->payment(2208, 17460, '2026-10-25');
        $c = $this->payment(2209, 17460, '2026-10-25');
        $op = $this->op(52760, '2026-10-25 12:00', ['comment' => 'Сплата за товар рахунок 2207/2208/2209 від 24.10.2026']);

        $s = $this->r->suggest($op);
        $this->assertSame(BankReconciler::LINK, $s['kind']);
        $this->assertEqualsCanonicalizing([$a, $b, $c], $s['ids']);

        $this->assertSame(1, $this->r->autoRun()['link']);
        $this->assertSame(BankOperation::MATCHED, $op->fresh()->reconcile_status);
        $this->assertSame(3, $op->transactions()->count());
        $this->assertEquals(10000, (float) $this->card->fresh()->balance); // гроші не рухались
    }

    public function test_payment_and_later_top_up_for_the_same_orders_are_split_correctly(): void
    {
        $a = $this->payment(2185, 6204, '2026-10-24');
        $b = $this->payment(2186, 4765, '2026-10-24');
        $c = DB::table('transactions')->insertGetId(['type' => 'income', 'category' => CashBook::CLIENT, 'amount' => 953, 'date' => '2026-10-25',
            'order_id' => 2186, 'account_id' => $this->card->id, 'created_at' => now(), 'updated_at' => now()]);
        $first = $this->op(10969, '2026-10-24 20:22', ['comment' => 'Оплата товару рахунок 2185/2186']);
        $topUp = $this->op(953, '2026-10-25 10:00', ['comment' => 'Оплата товару рахунок 2185/2186']);

        $this->r->autoRun();

        $this->assertEqualsCanonicalizing([$a, $b], $first->fresh()->transactions()->pluck('transactions.id')->all());
        $this->assertSame([$c], $topUp->fresh()->transactions()->pluck('transactions.id')->all());
    }

    public function test_same_amount_twice_is_resolved_by_payer_name_or_left_for_a_click(): void
    {
        $this->payment(3001, 2364, '2026-10-20', 'Бовтун Роман');
        $right = $this->payment(3002, 2364, '2026-10-20', 'Дмитрюк Дарія');
        $named = $this->op(2364, '2026-10-20 10:00', ['counter_name' => 'Дмитрюк Дарія Романівна']);
        $nobody = $this->op(2364, '2026-10-20 11:00', ['counter_name' => 'Хтось Інший']);

        $this->assertSame([$right], $this->r->suggest($named)['ids']);
        $this->assertTrue($this->r->suggest($named)['auto']);
        $this->assertFalse($this->r->suggest($nobody)['auto']);
    }

    public function test_supplier_payment_closes_the_invoice_on_click_and_reset_reopens_it(): void
    {
        $this->login('admin');
        $supplier = Supplier::create(['name' => 'Атабеков', 'inn' => '2634313872']);
        $wh = DB::table('warehouses')->insertGetId(['name' => 'Кухня']);
        $doc = StockDocument::create(['type' => 'receipt', 'warehouse_id' => $wh, 'supplier_id' => $supplier->id, 'operation_date' => '2026-10-13', 'is_paid' => false]);
        $doc->items()->create(['itemable_type' => Ingredient::class,
            'itemable_id' => Ingredient::create(['name' => 'Курка', 'unit' => 'kg', 'price_per_kg' => 200, 'stock' => 0])->id,
            'qty' => 130.31, 'total_price' => 26062.78]);
        $op = $this->op(-26062.78, '2026-10-14 09:00', ['counter_edrpou' => '2634313872', 'counter_name' => 'АТАБЕКОВ']);

        $s = $this->r->suggest($op);
        $this->assertSame(BankReconciler::INVOICES, $s['kind']);
        $this->assertFalse($s['auto']);
        $this->assertSame(0, $this->r->autoRun()['link']); // накладні — лише кліком
        $this->assertFalse((bool) $doc->fresh()->is_paid);

        $this->r->accept($op, null);

        $this->assertTrue((bool) $doc->fresh()->is_paid);
        $t = Transaction::where('stock_document_id', $doc->id)->first();
        $this->assertSame('2026-10-14', $t->date->toDateString());
        $this->assertSame($this->card->id, $t->account_id);
        $this->assertEqualsWithDelta(10000 - 26062.78, (float) $this->card->fresh()->balance, 0.01);

        $this->r->reset($op->fresh());
        $this->assertFalse((bool) $doc->fresh()->is_paid);
        $this->assertNull($op->fresh()->reconcile_status);
        $this->assertEquals(10000, (float) $this->card->fresh()->balance);
    }

    public function test_remembered_counterparty_is_handled_by_itself_next_time(): void
    {
        $this->login('admin');
        $first = $this->op(-2594.10, '2026-10-03 10:00', ['counter_edrpou' => '37955989', 'counter_name' => 'ГУК у Київ. обл.']);
        $this->assertNull($this->r->suggest($first));

        $this->r->record($first, 'Податки та банк', null, true, null);
        $this->assertSame(1, CounterpartyRule::count());

        $next = $this->op(-5188.20, '2026-10-20 10:00', ['counter_edrpou' => '37955989', 'counter_name' => 'ГУК уКиїв.обл']);
        $this->assertSame(1, $this->r->autoRun()['rule']);

        $t = $next->fresh()->transactions()->first();
        $this->assertSame('Податки та банк', $t->category);
        $this->assertEquals(5188.20, (float) $t->amount);
        $this->assertSame('2026-10-20', $t->date->toDateString());
    }

    public function test_transfer_to_own_card_is_not_an_expense(): void
    {
        $this->cash->update(['mono_iban' => 'UA222222222222222222222222222']);
        $op = $this->op(-50000, '2026-10-26 10:00', ['counter_iban' => 'UA222222222222222222222222222', 'counter_name' => 'Строя Катерина']);

        $this->assertSame(1, $this->r->autoRun()['internal']);
        $this->assertSame(BankOperation::IGNORED, $op->fresh()->reconcile_status);
        $this->assertSame(0, Transaction::count());
    }

    public function test_salary_by_bank_lowers_the_employee_debt(): void
    {
        $this->login('admin');
        $cook = Employee::create(['name' => 'Богдан', 'balance' => 9000]);
        $op = $this->op(-8000, '2026-10-10 10:00', ['counter_name' => 'Коваль Богдан']);

        $this->r->record($op, CashBook::SALARY, $cook->id, true, null);

        $this->assertEquals(1000, (float) $cook->fresh()->balance);
        $this->assertSame(CounterpartyRule::SALARY, CounterpartyRule::first()->action);
    }

    public function test_history_before_the_start_date_is_not_in_the_queue(): void
    {
        $this->op(1000, '2026-09-20 10:00');
        $this->op(1000, '2026-10-02 10:00');

        $this->assertSame(1, BankReconciler::queue()->count());
    }

    public function test_only_super_admin_sees_the_page_and_can_accept(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $boss = $this->login('admin'); // id 1 = супер адмін у конфігу
        $this->payment(2147, 1150, '2026-10-20', 'Денисова Ірина');
        $op = $this->op(1150, '2026-10-20 12:00', ['counter_name' => 'Денисова Ірина Миколаївна', 'comment' => 'замовлення 2147']);

        Livewire::test(ListBankReconciliation::class)
            ->assertSuccessful()
            ->assertCanSeeTableRecords([$op])
            ->callTableAction('accept', $op)
            ->assertHasNoTableActionErrors();
        $this->assertSame(BankOperation::MATCHED, $op->fresh()->reconcile_status);

        $this->login('manager');
        $this->assertFalse(BankReconciliationResource::canAccess());
        $this->assertSame(1, $boss->id);
    }
}
