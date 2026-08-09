<?php

namespace App\Filament\Resources\Cities\Schemas;

use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class CityForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(2)->schema([
                TextInput::make('name')->label('Місто')->required()->placeholder('Київ'),
                TextInput::make('slug')->label('Slug')->required()->unique(ignoreRecord: true)
                    ->helperText('Стає адресою: /kyiv. Змінювати у живого міста не варто.'),

                TextInput::make('name_locative')->label('У місцевому відмінку')->required()
                    ->placeholder('у Києві')
                    ->helperText('Підставляється в заголовки: «Прокат інструменту …».'),

                TextInput::make('phone')->label('Телефон')->required()
                    ->helperText('Показується в хедері й футері, коли обрано це місто.'),

                TextInput::make('delivery_note')->label('Рядок про доставку')
                    ->placeholder('доставка по Києву від 250 ₴')
                    ->helperText('Виводиться у верхній смузі сайту.'),

                TextInput::make('position')->label('Порядок')->numeric(),
            ]),

            Textarea::make('intro')->label('Опис на сторінці міста')->rows(4),
        ]);
    }
}
