<?php

namespace App\Filament\Resources\DailyMenuResource\Pages;

use App\Filament\Resources\DailyMenuResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListDailyMenus extends ListRecords
{
    protected static string $resource = DailyMenuResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
