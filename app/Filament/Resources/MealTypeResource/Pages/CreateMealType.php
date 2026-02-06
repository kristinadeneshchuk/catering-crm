<?php

namespace App\Filament\Resources\MealTypeResource\Pages;

use App\Filament\Resources\MealTypeResource;
use Filament\Actions;
use Filament\Resources\Pages\CreateRecord;

class CreateMealType extends CreateRecord
{
    protected static string $resource = MealTypeResource::class;

    protected function getRedirectUrl(): string
    {
    return $this->getResource()::getUrl('index');
    }
}
