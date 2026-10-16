<?php

namespace Tests\Feature;

use App\Filament\Resources\CashOperationResource;
use App\Filament\Resources\CashOperationResource\Pages\ListCashOperations;
use App\Models\Account;
use App\Models\Employee;
use App\Models\Ingredient;
use App\Models\StockDocument;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Accounting\CashBook;
use Filament\Facades\Filament;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Tests\Support\BuildsStockTestSchema;
use Tests\TestCase;

/**
 * «Каса»: ручні надходження й витрати за статтями, переказ, перерахунок.
 * У P&L іде лише те, що ще ніде не враховано, — без ЗП, накладних і переказів.
 */
class CashBookTest extends TestCase
{
    use BuildsStockTestSchema;

    protected Account $cash;
    protected Account $card;

    protected function setUp(): void
    {
        parent::setUp();

        Bus::fake();
        $this->buildStockSchema();

        foreach (['users', 'employees', 'positions', 'rate_history'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::create('users', function (Blueprint $t) {
            $t->id(); $t->string('name'); $t->string('email')->unique(); $t->string('password');
            $t->string('role')->default('manager'); $t->rememberToken(); $t->timestamps();
        });
        Schema::create('employees', function (Blueprint $t) {
            $t->id(); $t->string('name'); $t->decimal('balance', 12, 2)->default(0); $t->timestamps();
        });

        Schema::create('positions', function (Blueprint $t) {
            $t->id(); $t->string('key'); $t->string('payment_type')->nullable(); $t->timestamps();
        });
        Schema::create('rate_history', function (Blueprint $t) {
            $t->id(); $t->string('scope'); $t->decimal('value', 12, 2); $t->date('effective_from'); $t->timestamps();
        });

        $this->card = Account::create(['name' => 'ФОП картка', 'type' => 'card', 'balance' => 10000, 'is_default' => true]);
        $this->cash = Account::create(['name' => 'Готівка', 'type' => 'cash', 'balance' => 5000]);
    }

    protected function login(string $role = 'admin'): User
    {
        $user = User::create(['name' => $role, 'email' => $role.'@t.test', 'password' => 'x', 'role' => $role]);
        $this->actingAs($user);

        return $user;
    }

    public function test_an_expense_by_category_leaves_the_cash_and_goes_to_pnl(): void
    {
        $this->login();

        CashBook::record('expense', [
            'account_id' => $this->cash->id, 'category' => 'Господарські', 'amount' => 350,
            'date' => '2026-10-10', 'comment' => 'Губки, рукавички',
        ]);
        CashBook::record('expense', [
            'account_id' => $this->cash->id, 'category' => 'Оренда', 'amount' => 20000, 'date' => '2026-10-10',
        ]);

        $this->assertEquals(5000 - 350 - 20000, (float) $this->cash->fresh()->balance);

        // Оренда вже є в P&L з налаштувань — вдруге не рахуємо.
        $pnl = CashBook::pnlExpenses('2026-10-01', '2026-10-31');
        $this->assertEquals(['2026-10-10' => 350.0], $pnl['byDay']);
        $this->assertEquals(['Господарські' => 350.0], $pnl['byCategory']);
    }

    public function test_salary_from_cash_pays_off_the_employee_and_stays_out_of_pnl(): void
    {
        $this->login();
        $cook = Employee::create(['name' => 'Богдан', 'balance' => 3000]);

        CashBook::record('expense', [
            'account_id' => $this->cash->id, 'category' => CashBook::SALARY, 'employee_id' => $cook->id,
            'amount' => 2000, 'date' => now()->toDateString(),
        ]);

        $this->assertEquals(1000, (float) $cook->fresh()->balance);
        $this->assertEquals(3000, (float) $this->cash->fresh()->balance);
        $this->assertSame([], CashBook::pnlExpenses(now()->subDay()->toDateString(), now()->toDateString())['byDay']);
    }

    public function test_paying_an_invoice_from_cash_marks_it_paid_without_a_second_expense(): void
    {
        $this->login();
        $warehouse = DB::table('warehouses')->insertGetId(['name' => 'Кухня']);
        $doc = StockDocument::create(['type' => 'receipt', 'warehouse_id' => $warehouse, 'operation_date' => '2026-10-01', 'is_paid' => false]);
        $doc->items()->create([
            'itemable_type' => Ingredient::class,
            'itemable_id'   => Ingredient::create(['name' => 'Рис', 'unit' => 'kg', 'price_per_kg' => 50, 'stock' => 0])->id,
            'qty' => 10, 'total_price' => 500,
        ]);

        $t = CashBook::record('expense', [
            'account_id' => $this->cash->id, 'category' => CashBook::PURCHASE,
            'stock_document_id' => $doc->id, 'date' => '2026-10-05',
        ]);

        $this->assertTrue((bool) $doc->fresh()->is_paid);
        $this->assertSame(1, Transaction::where('type', 'expense')->count());
        $this->assertSame('2026-10-05', $t->fresh()->date->toDateString());
        $this->assertEquals(4500, (float) $this->cash->fresh()->balance);
    }

    public function test_a_transfer_moves_money_and_is_deleted_as_a_pair(): void
    {
        $this->login();

        [$out, $in] = CashBook::transfer($this->cash->id, $this->card->id, 3000, '2026-10-10');

        $this->assertEquals(2000, (float) $this->cash->fresh()->balance);
        $this->assertEquals(13000, (float) $this->card->fresh()->balance);
        $this->assertSame($in->id, CashBook::transferPair($out->fresh())->id);
        $this->assertSame(0, Transaction::moneyFlow()->count());

        CashBook::delete($in->fresh());

        $this->assertSame(0, Transaction::count());
        $this->assertEquals(5000, (float) $this->cash->fresh()->balance);
        $this->assertEquals(10000, (float) $this->card->fresh()->balance);
    }

    public function test_recount_writes_the_difference_and_the_running_balance_ends_at_it(): void
    {
        $this->login();
        CashBook::record('income', ['account_id' => $this->cash->id, 'category' => 'Внесення власником', 'amount' => 1000, 'date' => '2026-10-01']);

        $fix = CashBook::recount($this->cash->id, 4200, '2026-10-02');

        $this->assertSame('expense', $fix->type);
        $this->assertEquals(1800, (float) $fix->amount);
        $this->assertEquals(4200, (float) $this->cash->fresh()->balance);
        $this->assertNull(CashBook::recount($this->cash->id, 4200, '2026-10-02'));

        $after = CashBook::balancesAfter($this->cash->id);
        $this->assertEquals(4200, $after[$fix->id]);
        $this->assertEquals(6000, $after[Transaction::where('category', 'Внесення власником')->value('id')]);
    }

    public function test_a_manager_opens_the_page_and_adds_an_expense(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->login('manager');

        Livewire::test(ListCashOperations::class)
            ->assertSuccessful()
            ->callAction('expense', [
                'account_id' => $this->cash->id, 'date' => now()->toDateString(),
                'category' => 'Транспорт і пальне', 'amount' => 900, 'comment' => 'Бензин',
            ])
            ->assertHasNoActionErrors()
            ->assertCanSeeTableRecords(Transaction::all());

        $this->assertEquals(4100, (float) $this->cash->fresh()->balance);
        $this->assertTrue(CashOperationResource::canAccess());
    }

    public function test_a_cook_has_no_access(): void
    {
        $this->login('cook');

        $this->assertFalse(CashOperationResource::canAccess());
    }
}
