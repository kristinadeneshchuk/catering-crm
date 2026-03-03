<?php

namespace App\Filament\Resources\OrderCallResource\Pages;

use App\Filament\Resources\OrderCallResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListOrderCalls extends ListRecords
{
    protected static string $resource = OrderCallResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
