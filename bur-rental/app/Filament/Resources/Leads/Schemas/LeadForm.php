<?php

namespace App\Filament\Resources\Leads\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class LeadForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(2)->schema([
                Select::make('kind')->label('Тип')->options(self::kinds())->required(),
                Select::make('status')->label('Статус')->options(self::statuses())->required(),
                TextInput::make('name')->label('Ім\'я'),
                TextInput::make('phone')->label('Телефон')->tel(),
                TextInput::make('email')->label('Email')->email(),
                TextInput::make('company')->label('Компанія'),
                TextInput::make('edrpou')->label('ЄДРПОУ'),
                TextInput::make('context')->label('Звідки заявка')->disabled()
                    ->helperText('Сторінка, з якої надіслали форму.'),
            ]),

            Textarea::make('message')->label('Повідомлення')->rows(4),
        ]);
    }

    /** @return array<string, string> */
    public static function kinds(): array
    {
        return [
            'callback' => 'Передзвоніть мені',
            'b2b' => 'Запит КП (юрособа)',
            'contact' => 'Питання з контактів',
            'notify' => 'Повідомити, коли звільниться',
        ];
    }

    /** @return array<string, string> */
    public static function statuses(): array
    {
        return [
            'new' => 'Нова',
            'in_progress' => 'В роботі',
            'done' => 'Оброблена',
            'spam' => 'Спам',
        ];
    }
}
