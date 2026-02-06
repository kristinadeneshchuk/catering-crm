<?php

namespace App\Filament\Resources\DailyMenuResource\Pages;

use App\Filament\Resources\DailyMenuResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditDailyMenu extends EditRecord
{
    protected static string $resource = DailyMenuResource::class;

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
