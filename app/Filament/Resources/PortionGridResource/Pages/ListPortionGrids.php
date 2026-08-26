<?php

namespace App\Filament\Resources\PortionGridResource\Pages;

use App\Filament\Resources\PortionGridResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListPortionGrids extends ListRecords
{
    protected static string $resource = PortionGridResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\CreateAction::make()];
    }
}
