<?php
namespace App\Filament\Resources\StockItemCategoryResource\Pages;

use App\Filament\Resources\StockItemCategoryResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListStockItemCategories extends ListRecords
{
    protected static string $resource = StockItemCategoryResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\CreateAction::make()];
    }
}
