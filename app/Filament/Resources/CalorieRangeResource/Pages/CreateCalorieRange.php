<?php

namespace App\Filament\Resources\CalorieRangeResource\Pages;

use App\Filament\Resources\CalorieRangeResource;
use Filament\Actions;
use Filament\Resources\Pages\CreateRecord;

class CreateCalorieRange extends CreateRecord
{
    protected static string $resource = CalorieRangeResource::class;

        protected function getRedirectUrl(): string
{
    return $this->getResource()::getUrl('index');
}
}
