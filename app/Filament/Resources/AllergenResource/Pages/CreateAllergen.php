<?php

namespace App\Filament\Resources\AllergenResource\Pages;

use App\Filament\Resources\AllergenResource;
use Filament\Resources\Pages\CreateRecord;

class CreateAllergen extends CreateRecord
{
    protected static string $resource = AllergenResource::class;

    protected function getRedirectUrl(): string
{
    return $this->getResource()::getUrl('index');
}
}
