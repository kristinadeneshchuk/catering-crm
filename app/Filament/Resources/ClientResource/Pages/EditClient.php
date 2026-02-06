<?php

namespace App\Filament\Resources\ClientResource\Pages;

use App\Filament\Resources\ClientResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditClient extends EditRecord
{
    protected static string $resource = ClientResource::class;

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
