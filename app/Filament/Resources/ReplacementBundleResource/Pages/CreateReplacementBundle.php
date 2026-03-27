<?php

namespace App\Filament\Resources\ReplacementBundleResource\Pages;

use App\Filament\Resources\ReplacementBundleResource;
use Filament\Resources\Pages\CreateRecord;

class CreateReplacementBundle extends CreateRecord
{
    protected static string $resource = ReplacementBundleResource::class;

        protected function getRedirectUrl(): string
{
    return $this->getResource()::getUrl('index');
}
}
