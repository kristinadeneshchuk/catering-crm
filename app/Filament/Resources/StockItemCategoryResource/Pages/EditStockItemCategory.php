<?php
namespace App\Filament\Resources\StockItemCategoryResource\Pages;

use App\Filament\Resources\StockItemCategoryResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditStockItemCategory extends EditRecord
{
    protected static string $resource = StockItemCategoryResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\DeleteAction::make()];
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
