<?php

namespace App\Filament\Resources\Kits\Pages;

use App\Filament\Resources\Kits\KitResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListKits extends ListRecords
{
    protected static string $resource = KitResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
