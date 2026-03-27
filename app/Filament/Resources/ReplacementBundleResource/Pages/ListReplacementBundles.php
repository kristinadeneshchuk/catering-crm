<?php

namespace App\Filament\Resources\ReplacementBundleResource\Pages;

use App\Filament\Resources\ReplacementBundleResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListReplacementBundles extends ListRecords
{
    protected static string $resource = ReplacementBundleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
