<?php

namespace App\Filament\Resources\MeterReadingResource\Pages;

use App\Filament\Resources\MeterReadingResource;
use Filament\Actions;
use Filament\Resources\Pages\ManageRecords;

class ManageMeterReadings extends ManageRecords
{
    protected static string $resource = MeterReadingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()->label('Внести показник')
                ->mutateFormDataUsing(fn (array $data) => $data + ['user_id' => auth()->id()]),
        ];
    }

    protected function getHeaderWidgets(): array
    {
        return [MeterReadingResource\Widgets\EnergySummaryTable::class];
    }
}
