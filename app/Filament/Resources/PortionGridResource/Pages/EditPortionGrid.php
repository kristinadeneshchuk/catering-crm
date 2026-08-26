<?php

namespace App\Filament\Resources\PortionGridResource\Pages;

use App\Filament\Resources\PortionGridResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditPortionGrid extends EditRecord
{
    protected static string $resource = PortionGridResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\DeleteAction::make()];
    }
}
