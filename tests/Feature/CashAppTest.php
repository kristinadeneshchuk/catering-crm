<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Transaction;
use App\Services\Accounting\CashBook;
use Tests\Support\CashWorld;
use Tests\TestCase;

/**
 * «Каса» на телефоні (/cash-app): ті самі гроші й правила, що в адмінці,
 * через JSON. Плюс захист від подвійного запису на поганому звʼязку.
 */
class CashAppTest extends TestCase
{
    use CashWorld;

    protected function expenseBody(array $over = []): array
    {
        return array_merge([
            'kind' => 'expense', 'account_id' => $this->cash->id, 'date' => now()->toDateString(),
            'category' => 'Господарські', 'amount' => 350, 'comment' => 'Губки',
        ], $over);
    }

    public function test_the_page_and_pwa_files_open(): void
    {
        $this->get('/cash-app')->assertRedirect('/admin/login');
        $this->get('/cash-app/manifest.webmanifest')->assertOk()->assertJsonPath('start_url', '/cash-app')->assertJsonPath('display', 'standalone');
        $this->get('/cash-app/sw.js')->assertOk()->assertHeader('Content-Type', 'application/javascript');

        $this->login('manager');
        $this->get('/cash-app')->assertOk()->assertSee('manifest.webmanifest', false)->assertSee('Залишок');
    }

    public function test_a_cook_is_not_let_in(): void
    {
        $this->login('cook');

        $this->get('/cash-app')->assertForbidden();
        $this->getJson('/cash-app/data')->assertForbidden();
        $this->postJson('/cash-app/ops', $this->expenseBody())->assertForbidden();
    }

    public function test_income_and_expense_move_the_balance_and_show_in_the_list(): void
    {
        $this->login('manager');

        $this->postJson('/cash-app/ops', ['kind' => 'income', 'account_id' => $this->cash->id, 'date' => now()->toDateString(),
            'category' => 'Внесення власником', 'amount' => '1500'])->assertOk()->assertJsonPath('op.amount', 1500);
        $this->postJson('/cash-app/ops', $this->expenseBody())->assertOk()->assertJsonPath('op.amount', -350);

        $this->getJson('/cash-app/data?account='.$this->cash->id)
            ->assertOk()
            ->assertJsonPath('balance', 6150)
            ->assertJsonPath('today.in', 1500)
            ->assertJsonPath('today.out', 350)
            ->assertJsonPath('ops.0.category', 'Господарські')
            ->assertJsonPath('ops.0.balance', 6150)
            ->assertJsonPath('ops.1.balance', 6500)
            ->assertJsonPath('ops.0.user', 'manager');
    }

    public function test_the_same_form_sent_twice_is_written_once(): void
    {
        $this->login('manager');

        $this->postJson('/cash-app/ops', $this->expenseBody(), ['X-Idempotency-Key' => 'form-1'])->assertOk();
        $this->postJson('/cash-app/ops', $this->expenseBody(), ['X-Idempotency-Key' => 'form-1'])->assertOk()->assertJsonPath('duplicate', true);
        $this->postJson('/cash-app/ops', $this->expenseBody(), ['X-Idempotency-Key' => 'form-2'])->assertOk();

        $this->assertSame(2, Transaction::count());
        $this->assertEquals(5000 - 700, (float) $this->cash->fresh()->balance);
    }

    public function test_bad_input_is_refused_with_field_errors(): void
    {
        $this->login('manager');

        $this->postJson('/cash-app/ops', $this->expenseBody(['amount' => null, 'category' => 'Казна']))
            ->assertUnprocessable()->assertJsonValidationErrors(['amount', 'category']);
        $this->postJson('/cash-app/ops', $this->expenseBody(['category' => CashBook::SALARY]))
            ->assertUnprocessable()->assertJsonValidationErrors(['employee_id']);
        $this->postJson('/cash-app/ops', $this->expenseBody(['category' => 'Інше', 'comment' => null]))
            ->assertUnprocessable()->assertJsonValidationErrors(['comment']);
        $this->postJson('/cash-app/ops', $this->expenseBody(['date' => now()->addDay()->toDateString()]))
            ->assertUnprocessable()->assertJsonValidationErrors(['date']);

        $this->assertSame(0, Transaction::count());
    }

    public function test_salary_from_the_phone_pays_off_the_employee(): void
    {
        $this->login('manager');
        $cook = Employee::create(['name' => 'Богдан', 'balance' => 3000]);

        $this->postJson('/cash-app/ops', $this->expenseBody(['category' => CashBook::SALARY, 'employee_id' => $cook->id, 'amount' => 2000]))->assertOk();

        $this->assertEquals(1000, (float) $cook->fresh()->balance);
        $this->getJson('/cash-app/refs')->assertJsonFragment(['name' => 'Богдан', 'debt' => 1000]);
    }

    public function test_transfer_and_recount(): void
    {
        $this->login('manager');

        $this->postJson('/cash-app/transfer', ['account_id' => $this->cash->id, 'to_account_id' => $this->cash->id, 'amount' => 100, 'date' => now()->toDateString()])
            ->assertUnprocessable()->assertJsonValidationErrors(['to_account_id']);
        $this->postJson('/cash-app/transfer', ['account_id' => $this->cash->id, 'to_account_id' => $this->card->id, 'amount' => 1000, 'date' => now()->toDateString()])->assertOk();
        $this->postJson('/cash-app/recount', ['account_id' => $this->cash->id, 'actual' => 3900, 'date' => now()->toDateString()])
            ->assertOk()->assertJsonPath('message', 'Записано різницю −100.00 ₴');

        $this->assertEquals(3900, (float) $this->cash->fresh()->balance);
        $this->assertEquals(11000, (float) $this->card->fresh()->balance);
        // Переказ і перерахунок — не прихід і не витрата за день.
        $this->getJson('/cash-app/data?account='.$this->cash->id)->assertJsonPath('today.out', 0);
    }

    public function test_a_manager_edits_today_only_and_cannot_backdate(): void
    {
        $this->login('admin');
        $old = CashBook::record('expense', $this->expenseBody(['date' => now()->subDay()->toDateString()]));
        $this->login('manager2');
        auth()->user()->update(['role' => 'manager']);
        $today = Transaction::find($this->postJson('/cash-app/ops', $this->expenseBody())->json('op.id'));

        $this->patchJson('/cash-app/ops/'.$old->id, ['date' => $old->date->toDateString(), 'amount' => 1])->assertForbidden();
        $this->patchJson('/cash-app/ops/'.$today->id, ['date' => now()->subDay()->toDateString(), 'amount' => 400, 'category' => 'Господарські'])
            ->assertUnprocessable()->assertJsonValidationErrors(['date']);
        $this->patchJson('/cash-app/ops/'.$today->id, ['date' => now()->toDateString(), 'amount' => 400, 'category' => 'Реклама', 'comment' => 'Листівки'])
            ->assertOk()->assertJsonPath('op.amount', -400)->assertJsonPath('op.category', 'Реклама');

        $this->assertEquals(5000 - 350 - 400, (float) $this->cash->fresh()->balance);
        $this->deleteJson('/cash-app/ops/'.$today->id)->assertForbidden();
    }

    public function test_salary_category_cannot_be_changed_on_edit(): void
    {
        $this->login('admin');
        $cook = Employee::create(['name' => 'Богдан', 'balance' => 3000]);
        $t = CashBook::record('expense', $this->expenseBody(['category' => CashBook::SALARY, 'employee_id' => $cook->id, 'amount' => 2000]));

        $this->patchJson('/cash-app/ops/'.$t->id, ['date' => now()->toDateString(), 'amount' => 2500, 'category' => 'Реклама'])
            ->assertUnprocessable()->assertJsonValidationErrors(['category']);
        $this->patchJson('/cash-app/ops/'.$t->id, ['date' => now()->toDateString(), 'amount' => 2500])->assertOk();

        $this->assertSame(CashBook::SALARY, $t->fresh()->category);
        $this->assertEquals(500, (float) $cook->fresh()->balance);
    }

    public function test_admin_deletes_and_history_shows_who_did_what(): void
    {
        $this->login('admin');
        $id = $this->postJson('/cash-app/ops', $this->expenseBody())->json('op.id');
        $this->patchJson('/cash-app/ops/'.$id, ['date' => now()->toDateString(), 'amount' => 420, 'category' => 'Господарські'])->assertOk();

        $this->getJson("/cash-app/ops/{$id}/history")
            ->assertOk()
            ->assertJsonPath('items.0.event', 'Створено')
            ->assertJsonPath('items.1.event', 'Оновлено')
            ->assertJsonPath('items.1.who', 'admin')
            ->assertJsonFragment(['label' => 'Сума', 'old' => '350.00', 'new' => '420.00']);

        $this->deleteJson('/cash-app/ops/'.$id)->assertOk();
        $this->assertEquals(5000, (float) $this->cash->fresh()->balance);
    }
}
