<?php

namespace App\Filament\Resources\StockDocumentResource\Pages;

use App\Filament\Resources\StockDocumentResource;
use Filament\Actions;
use Filament\Resources\Pages\CreateRecord;

class CreateStockDocument extends CreateRecord
{
    protected static string $resource = StockDocumentResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['status'] = ! empty($this->data['is_draft'])
            ? \App\Models\StockDocument::STATUS_DRAFT
            : \App\Models\StockDocument::STATUS_POSTED;

        return $data;
    }

    protected function getRedirectUrl(): string
    {
    return $this->getResource()::getUrl('index');
    }
}
