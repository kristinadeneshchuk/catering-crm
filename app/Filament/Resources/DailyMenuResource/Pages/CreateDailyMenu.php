<?php

namespace App\Filament\Resources\DailyMenuResource\Pages;

use App\Filament\Resources\DailyMenuResource;
use Filament\Actions;
use Filament\Resources\Pages\CreateRecord;

class CreateDailyMenu extends CreateRecord
{
    protected static string $resource = DailyMenuResource::class;

    protected function getRedirectUrl(): string
{
    return $this->getResource()::getUrl('index');
}
}
