<?php

namespace App\Filament\Resources\Clients\Pages;

use App\Filament\Resources\Clients\ClientResource;
use Filament\Resources\Pages\ListRecords;

class ListClients extends ListRecords
{
    protected static string $resource = ClientResource::class;

    /** Кнопки «створити» немає свідомо: клієнт заводиться сам при вході. */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
