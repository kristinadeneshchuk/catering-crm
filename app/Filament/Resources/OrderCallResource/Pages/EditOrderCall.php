<?php

namespace App\Filament\Resources\OrderCallResource\Pages;

use App\Filament\Resources\OrderCallResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditOrderCall extends EditRecord
{
    protected static string $resource = OrderCallResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
