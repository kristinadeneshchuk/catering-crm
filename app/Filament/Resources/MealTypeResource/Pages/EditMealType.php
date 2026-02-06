<?php

namespace App\Filament\Resources\MealTypeResource\Pages;

use App\Filament\Resources\MealTypeResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditMealType extends EditRecord
{
    protected static string $resource = MealTypeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
    
    protected function getRedirectUrl(): string
    {
    return $this->getResource()::getUrl('index');
    }
}
