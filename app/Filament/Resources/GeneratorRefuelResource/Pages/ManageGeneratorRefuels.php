<?php

namespace App\Filament\Resources\GeneratorRefuelResource\Pages;

use App\Filament\Resources\GeneratorRefuelResource;
use Filament\Actions;
use Filament\Resources\Pages\ManageRecords;

class ManageGeneratorRefuels extends ManageRecords
{
    protected static string $resource = GeneratorRefuelResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()->label('Внести заправку')
                ->mutateFormDataUsing(fn (array $data) => $data + ['user_id' => auth()->id()]),
        ];
    }
}
