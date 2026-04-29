<?php

namespace App\Filament\Resources\MessengerAccountResource\Pages;

use App\Filament\Resources\MessengerAccountResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListMessengerAccounts extends ListRecords
{
    protected static string $resource = MessengerAccountResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()->label('Підключити акаунт'),
        ];
    }
}
