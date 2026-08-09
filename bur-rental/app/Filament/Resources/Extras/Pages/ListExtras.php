<?php

namespace App\Filament\Resources\Extras\Pages;

use App\Filament\Resources\Extras\ExtraResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListExtras extends ListRecords
{
    protected static string $resource = ExtraResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
