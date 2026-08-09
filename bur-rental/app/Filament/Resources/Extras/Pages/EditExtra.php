<?php

namespace App\Filament\Resources\Extras\Pages;

use App\Filament\Resources\Extras\ExtraResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditExtra extends EditRecord
{
    protected static string $resource = ExtraResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
