<?php

namespace App\Filament\Resources\Clients\Pages;

use App\Filament\Resources\Clients\ClientResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditClient extends EditRecord
{
    protected static string $resource = ClientResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Видалення прибирає кабінет і обране, але не броні: вони лишаються
            // в історії компанії з телефоном і сумами, просто без власника.
            DeleteAction::make(),
        ];
    }
}
