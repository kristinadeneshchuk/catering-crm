<?php

namespace App\Filament\Resources\Branches\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class BranchForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(2)->schema([
                TextInput::make('name')->label('Назва')->required()->placeholder('Позняки'),
                TextInput::make('slug')->label('Slug')->required(),

                Select::make('city_id')->label('Місто')->relationship('city', 'name')
                    ->required()->searchable()->preload(),

                Select::make('district_id')->label('Район')->relationship('district', 'name')
                    ->searchable()->preload(),

                TextInput::make('address')->label('Адреса')->required(),
                TextInput::make('phone')->label('Телефон'),
                TextInput::make('manager')->label('Менеджер'),
                TextInput::make('distance_km')->label('Відстань від центру, км')->numeric()->step(0.1),
            ]),

            Section::make('Графік')->columns(2)->schema([
                TextInput::make('hours')->label('Графік роботи')->placeholder('щодня 8:00–20:00'),
                TextInput::make('last_intake')->label('Останній прийом техніки')->placeholder('до 19:30')
                    ->helperText('Клієнти планують повернення саме по цьому часу.'),
            ]),

            Section::make('Як доїхати і рейтинг')->columns(2)->schema([
                Textarea::make('directions')->label('Як доїхати')->rows(4)->columnSpanFull()
                    ->helperText('Метро, авто, парковка, номер воріт для завантаження.'),
                TextInput::make('lat')->label('Широта')->numeric(),
                TextInput::make('lng')->label('Довгота')->numeric(),
                TextInput::make('rating')->label('Рейтинг Google')->numeric()->step(0.1),
                TextInput::make('reviews_count')->label('Відгуків у Google')->numeric(),
                TextInput::make('position')->label('Порядок')->numeric(),
            ]),
        ]);
    }
}
