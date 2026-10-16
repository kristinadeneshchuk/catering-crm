<?php

namespace App\Filament\Resources\CashOperationResource\Pages;

use App\Filament\Resources\CashOperationResource;
use App\Models\Account;
use App\Services\Accounting\CashBook;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Resources\Pages\ListRecords;

class ListCashOperations extends ListRecords
{
    protected static string $resource = CashOperationResource::class;

    /** Рахунок з фільтра — його підставляємо у форми і показуємо залишок. */
    protected function currentAccountId(): ?int
    {
        $id = $this->tableFilters['account_id']['value'] ?? null;

        return $id ? (int) $id : CashBook::defaultCashAccountId();
    }

    public function getSubheading(): ?string
    {
        $account = Account::find($this->currentAccountId());

        return $account
            ? 'Залишок «'.$account->name.'»: '.number_format((float) $account->balance, 2, '.', ' ').' ₴'
            : null;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('income')
                ->label('+ Надходження')
                ->color('success')
                ->modalHeading('Надходження')
                ->modalWidth('xl')
                ->form(fn () => CashOperationResource::incomeForm($this->currentAccountId()))
                ->action(function (array $data) {
                    CashBook::record('income', $data);
                    CashOperationResource::notify('Надходження внесено');
                }),

            Action::make('expense')
                ->label('− Витрата')
                ->color('danger')
                ->modalHeading('Витрата')
                ->modalWidth('xl')
                ->form(fn () => CashOperationResource::expenseForm($this->currentAccountId()))
                ->action(function (array $data) {
                    CashBook::record('expense', $data);
                    CashOperationResource::notify('Витрату внесено');
                }),

            Action::make('transfer')
                ->label('⇄ Переказ')
                ->color('info')
                ->modalHeading('Переказ між рахунками')
                ->modalDescription('Напр., здали готівку на картку. Не витрата — у звіти не йде.')
                ->modalWidth('xl')
                ->form(fn () => [
                    Forms\Components\Grid::make(2)->schema([
                        CashOperationResource::accountField($this->currentAccountId())->label('Звідки'),
                        Forms\Components\Select::make('to_account_id')->label('Куди')
                            ->options(fn () => Account::orderBy('id')->pluck('name', 'id'))
                            ->different('account_id')->required(),
                        Forms\Components\TextInput::make('amount')->label('Сума')->numeric()->minValue(0.01)->prefix('₴')->required(),
                        CashOperationResource::dateField(),
                    ]),
                    Forms\Components\TextInput::make('comment')->label('Коментар'),
                ])
                ->action(function (array $data) {
                    CashBook::transfer((int) $data['account_id'], (int) $data['to_account_id'], (float) $data['amount'], $data['date'], $data['comment'] ?? null);
                    CashOperationResource::notify('Переказ внесено');
                }),

            Action::make('recount')
                ->label('Перерахунок')
                ->color('gray')
                ->icon('heroicon-o-calculator')
                ->modalHeading('Перерахунок каси')
                ->modalDescription('Порахуйте гроші й впишіть, скільки фактично. Різниця запишеться окремою операцією «Перерахунок каси» — у звіти не йде.')
                ->modalWidth('lg')
                ->form(fn () => [
                    CashOperationResource::accountField($this->currentAccountId()),
                    Forms\Components\TextInput::make('actual')->label('Фактично на руках')->numeric()->minValue(0)->prefix('₴')->required(),
                    CashOperationResource::dateField(),
                    Forms\Components\TextInput::make('comment')->label('Коментар'),
                ])
                ->action(function (array $data) {
                    $t = CashBook::recount((int) $data['account_id'], (float) $data['actual'], $data['date'], $data['comment'] ?? null);
                    CashOperationResource::notify($t
                        ? 'Записано різницю '.($t->type === 'income' ? '+' : '−').number_format((float) $t->amount, 2, '.', ' ').' ₴'
                        : 'Зійшлось копійка в копійку');
                }),
        ];
    }
}
