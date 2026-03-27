<?php
namespace App\Filament\Resources\StockItemCategoryResource\Pages;

use App\Filament\Resources\StockItemCategoryResource;
use Filament\Resources\Pages\CreateRecord;

class CreateStockItemCategory extends CreateRecord
{
    protected static string $resource = StockItemCategoryResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
