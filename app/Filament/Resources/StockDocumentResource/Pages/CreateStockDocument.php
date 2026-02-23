<?php

namespace App\Filament\Resources\StockDocumentResource\Pages;

use App\Filament\Resources\StockDocumentResource;
use Filament\Actions;
use Filament\Resources\Pages\CreateRecord;

class CreateStockDocument extends CreateRecord
{
    protected static string $resource = StockDocumentResource::class;

    protected function getRedirectUrl(): string
    {
    return $this->getResource()::getUrl('index');
    }
}
