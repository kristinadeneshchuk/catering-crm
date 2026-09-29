<?php

namespace App\Filament\Resources\DeliveryZones\Schemas;

use App\Models\DeliveryZone;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class DeliveryZoneForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('city_id')->label('Місто')->relationship('city', 'name')
                ->required()->searchable()->preload(),

            TextInput::make('name')->label('Зона')->required()->placeholder('У межах міста'),
            TextInput::make('slug')->label('Slug')->required(),

            Select::make('price_mode')->label('Як рахується ціна')->required()->default(DeliveryZone::FIXED)
                ->options([
                    DeliveryZone::FIXED => 'Фіксована — сума зони плюс доплати за вагу',
                    DeliveryZone::QUOTE => 'За домовленістю — суму називає менеджер',
                    DeliveryZone::INFO => 'Рядок-правило — тільки для таблиці, у бронюванні не вибирається',
                ]),

            TextInput::make('price')->label('Вартість, ₴')->numeric()->required()
                ->helperText('0 = безкоштовно. Доплати за гідроборт і вагу рахуються окремо. Для «за домовленістю» не використовується.'),

            TextInput::make('eta')->label('Коли привеземо')
                ->placeholder('того ж дня при замовленні до 15:00'),

            TextInput::make('note')->label('Примітка')
                ->placeholder('Ірпінь, Бровари, Вишневе'),

            TextInput::make('position')->label('Порядок')->numeric(),
        ]);
    }
}
