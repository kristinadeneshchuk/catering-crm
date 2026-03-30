<?php

namespace App\Filament\Resources\StockDocumentResource\Pages;

use App\Filament\Resources\StockDocumentResource;
use App\Filament\Widgets\StockDocumentStats;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListStockDocuments extends ListRecords
{
    protected static string $resource = StockDocumentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }

    protected function getHeaderWidgets(): array
    {
        return [
            StockDocumentStats::class,
        ];
    }
}
