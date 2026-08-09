<?php

namespace App\Filament\Resources\Districts\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class DistrictForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('city_id')->label('Місто')->relationship('city', 'name')
                ->required()->searchable()->preload(),

            TextInput::make('name')->label('Район')->required()
                ->live(onBlur: true)
                ->afterStateUpdated(fn (?string $state, callable $set, string $operation) => $operation === 'create' && $state
                    ? $set('slug', Str::slug($state))
                    : null),

            TextInput::make('slug')->label('Slug')->required(),

            Textarea::make('intro')->label('Локальний текст')->rows(4)
                ->helperText('Чим цей район відрізняється: найближча філія, доставка, типові роботи.'),
        ]);
    }
}
