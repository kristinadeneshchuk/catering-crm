<?php

namespace App\Filament\Resources\ClientResource\Pages;

use App\Filament\Resources\ClientResource;
use Filament\Resources\Pages\CreateRecord;

class CreateClient extends CreateRecord
{
    protected static string $resource = ClientResource::class;

    protected function getRedirectUrl(): string
    {
        // Після створення — одразу на edit, де є всі вкладки (адреси, замовлення, оплати)
        return $this->getResource()::getUrl('edit', ['record' => $this->record->id]);
    }

    protected function afterCreate(): void
    {
        $addresses = $this->form->getRawState()['addresses_data'] ?? [];

        foreach ($addresses as $addressData) {
            if (empty($addressData['address'])) continue;

            $this->record->addresses()->create([
                'label'             => $addressData['label'] ?? 'Адреса',
                'address'           => $addressData['address'],
                'address_entrance'  => $addressData['address_entrance'] ?? null,
                'address_apartment' => $addressData['address_apartment'] ?? null,
                'address_floor'     => $addressData['address_floor'] ?? null,
                'delivery_comment'  => $addressData['delivery_comment'] ?? null,
                'is_default'        => (bool) ($addressData['is_default'] ?? false),
            ]);
        }
    }
}
