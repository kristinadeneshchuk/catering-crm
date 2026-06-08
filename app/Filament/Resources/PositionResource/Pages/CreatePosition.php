<?php

namespace App\Filament\Resources\PositionResource\Pages;

use App\Filament\Resources\PositionResource;
use App\Models\Position;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Str;

class CreatePosition extends CreateRecord
{
    protected static string $resource = PositionResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // Стабільний унікальний ключ із назви (логіка кухня/кур'єр звіряється саме за ключем)
        $base = Str::slug($data['name'] ?? '') ?: 'pos';
        $key = $base;
        $i = 1;
        while (Position::where('key', $key)->exists()) {
            $key = $base . '-' . (++$i);
        }
        $data['key'] = $key;

        return $data;
    }
}
