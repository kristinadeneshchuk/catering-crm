<?php

namespace App\Filament\Resources\AccountResource\Pages;

use App\Filament\Resources\AccountResource;
use App\Models\Transaction;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditAccount extends EditRecord
{
    protected static string $resource = AccountResource::class;

    private float $balanceBefore;

    protected function beforeSave(): void
    {
        $this->balanceBefore = (float) $this->record->balance;
    }

    protected function afterSave(): void
    {
        $balanceAfter = (float) $this->record->balance;
        $diff = round($balanceAfter - $this->balanceBefore, 2);

        if ($diff == 0) return;

        Transaction::create([
            'account_id' => $this->record->id,
            'type'       => $diff > 0 ? 'income' : 'expense',
            'amount'     => abs($diff),
            'category'   => 'Корекція балансу',
            'date'       => now()->toDateString(),
            'user_id'    => auth()->id(),
            'comment'    => 'Ручна корекція балансу адміністратором. Було: ₴' . number_format($this->balanceBefore, 2) . ' → Стало: ₴' . number_format($balanceAfter, 2),
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
