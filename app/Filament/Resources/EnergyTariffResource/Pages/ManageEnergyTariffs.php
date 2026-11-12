<?php

namespace App\Filament\Resources\EnergyTariffResource\Pages;

use App\Filament\Resources\EnergyTariffResource;
use Filament\Actions;
use Filament\Resources\Pages\ManageRecords;

class ManageEnergyTariffs extends ManageRecords
{
    protected static string $resource = EnergyTariffResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\CreateAction::make()->label('Новий тариф')];
    }
}
