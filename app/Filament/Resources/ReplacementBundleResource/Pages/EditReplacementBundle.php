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

    /** Правила шаблону змінили — оновлюємо живі замовлення всіх його клієнтів. */
    protected function afterSave(): void
    {
        $swaps = app(\App\Services\Orders\BundleDishSwaps::class);
        foreach ($this->record->affectedClients() as $client) {
            $swaps->syncClient($client);
        }
    }

    protected function getRedirectUrl(): string
{
    return $this->getResource()::getUrl('index');
}
}
