<?php

namespace App\Filament\Resources\Clients\Schemas;

use App\Support\Phone;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ClientForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Клієнт')->columns(2)->schema([
                // Телефон — це логін. Змінити його з адмінки означає забрати
                // в людини доступ до власного кабінету, тому тільки читання.
                TextInput::make('phone')
                    ->label('Телефон')
                    ->formatStateUsing(fn (?string $state) => Phone::format($state))
                    ->disabled()
                    ->dehydrated(false)
                    ->helperText('Логін клієнта — не змінюється. Новий номер = новий кабінет.'),

                TextInput::make('name')->label('Ім\'я'),
                TextInput::make('email')->label('Пошта')->email(),
                TextInput::make('company')->label('Компанія'),
                TextInput::make('edrpou')->label('ЄДРПОУ')->numeric(),
            ]),
        ]);
    }
}
