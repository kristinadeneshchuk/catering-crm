<?php

namespace Tests\Feature;

use App\Filament\Resources\CashOperationResource;
use App\Filament\Resources\TransactionResource;
use App\Models\Account;
use App\Models\Transaction;
use Tests\Support\BuildsCourierTestSchema;
use Tests\TestCase;

class HiddenCryptoHistoryTest extends TestCase
{
    use BuildsCourierTestSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildCourierSchema();
    }

    public function test_hidden_account_operations_are_not_listed(): void
    {
        $hidden = Account::forceCreate(['id' => Account::HIDDEN_HISTORY_IDS[0], 'name' => 'ТЕСТ від ХП крипта', 'type' => 'online', 'balance' => 0]);
        $open = Account::forceCreate(['name' => 'ТЕСТ від ХП каса', 'type' => 'cash', 'balance' => 0]);

        $make = fn ($accountId) => Transaction::withoutEvents(fn () => Transaction::forceCreate([
            'type' => 'expense', 'category' => 'Інше', 'amount' => 1, 'date' => '2026-11-01', 'account_id' => $accountId,
        ]))->id;
        $hiddenTx = $make($hidden->id);
        $openTx = $make($open->id);
        $noAccountTx = $make(null);

        $journal = TransactionResource::getEloquentQuery()->pluck('id');
        $this->assertNotContains($hiddenTx, $journal);
        $this->assertContains($openTx, $journal);
        $this->assertContains($noAccountTx, $journal);

        $cash = CashOperationResource::getEloquentQuery()->pluck('id');
        $this->assertNotContains($hiddenTx, $cash);
        $this->assertContains($openTx, $cash);
        $this->assertFalse(CashOperationResource::shouldRegisterNavigation());
    }
}
