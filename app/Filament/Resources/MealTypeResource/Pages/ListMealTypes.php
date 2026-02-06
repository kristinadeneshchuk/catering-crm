<?php

namespace App\Filament\Resources\MealTypeResource\Pages;

use App\Filament\Resources\MealTypeResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListMealTypes extends ListRecords
{
    protected static string $resource = MealTypeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
