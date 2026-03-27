<?php

namespace App\Filament\Resources\ReplacementBundleResource\Pages;

use App\Filament\Resources\ReplacementBundleResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditReplacementBundle extends EditRecord
{
    protected static string $resource = ReplacementBundleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }

    protected function getRedirectUrl(): string
{
    return $this->getResource()::getUrl('index');
}
}
