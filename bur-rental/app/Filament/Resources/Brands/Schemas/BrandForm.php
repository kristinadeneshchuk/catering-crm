<?php

namespace App\Filament\Resources\Brands\Schemas;

use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class BrandForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(2)->schema([
                TextInput::make('name')->label('Назва')->required()
                    ->live(onBlur: true)
                    ->afterStateUpdated(fn (?string $state, callable $set, string $operation) => $operation === 'create' && $state
                        ? $set('slug', Str::slug($state))
                        : null),
                TextInput::make('slug')->label('Slug')->required()->unique(ignoreRecord: true),
                TextInput::make('country')->label('Країна'),
                ColorPicker::make('accent_color')->label('Фірмовий колір')
                    ->helperText('Колір самого інструменту. На сторінці не використовується як тло — бренд не має конкурувати з нашим зеленим.'),
            ]),

            Section::make('Тексти сторінки бренду')->schema([
                Textarea::make('about')->label('Про бренд')->rows(4),
                Textarea::make('why')->label('Чому тримаємо в парку')->rows(3),
            ]),
        ]);
    }
}
