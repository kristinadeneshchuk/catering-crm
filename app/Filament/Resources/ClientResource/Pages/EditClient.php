<?php

namespace App\Filament\Resources\ClientResource\Pages;

use App\Filament\Resources\ClientResource;
use App\Filament\Resources\OrderResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditClient extends EditRecord
{
    protected static string $resource = ClientResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('create_order')
                ->label('Нове замовлення')
                ->icon('heroicon-o-plus-circle')
                ->color('success')
                ->url(fn () => OrderResource::getUrl('create', ['client_id' => $this->record->id])),
            Actions\DeleteAction::make(),
        ];
    }

    protected function getRedirectUrl(): string
{
    return $this->getResource()::getUrl('index');
}

public function hasCombinedRelationManagerTabsWithContent(): bool
    {
        return true;
    }

    // 2. Цей метод дає назву першій вкладці (де ваша анкета)
    public function getContentTabLabel(): ?string
    {
        return 'Інформація про клієнта';
    }
}
