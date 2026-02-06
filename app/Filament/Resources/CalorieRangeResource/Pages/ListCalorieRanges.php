<?php

namespace App\Filament\Resources\CalorieRangeResource\Pages;

use App\Filament\Resources\CalorieRangeResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListCalorieRanges extends ListRecords
{
    protected static string $resource = CalorieRangeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
