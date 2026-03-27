<?php

namespace App\Filament\Resources\AllergenResource\Pages;

use App\Filament\Resources\AllergenResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListAllergens extends ListRecords
{
    protected static string $resource = AllergenResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
