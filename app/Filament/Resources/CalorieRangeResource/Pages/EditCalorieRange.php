<?php

namespace App\Filament\Resources\CalorieRangeResource\Pages;

use App\Filament\Resources\CalorieRangeResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditCalorieRange extends EditRecord
{
    protected static string $resource = CalorieRangeResource::class;

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
