<?php

namespace App\Filament\Resources\MessengerAccountResource\Pages;

use App\Filament\Resources\MessengerAccountResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditMessengerAccount extends EditRecord
{
    protected static string $resource = MessengerAccountResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
