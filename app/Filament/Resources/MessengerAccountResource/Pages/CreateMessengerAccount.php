<?php

namespace App\Filament\Resources\MessengerAccountResource\Pages;

use App\Filament\Resources\MessengerAccountResource;
use Filament\Resources\Pages\CreateRecord;

class CreateMessengerAccount extends CreateRecord
{
    protected static string $resource = MessengerAccountResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['connected_by_user_id'] = auth()->id();

        return $data;
    }
}
