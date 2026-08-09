<?php

namespace App\Filament\Resources\Extras\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class ExtraForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->label('Назва')->required()
                ->live(onBlur: true)
                ->afterStateUpdated(fn (?string $state, callable $set, string $operation) => $operation === 'create' && $state
                    ? $set('slug', Str::slug($state))
                    : null),
            TextInput::make('slug')->label('Slug')->required()->unique(ignoreRecord: true),
            TextInput::make('sub')->label('Підпис')->placeholder('купівля, шт'),
            TextInput::make('price')->label('Ціна, ₴')->numeric()->required()
                ->helperText('Витратник продається, а не орендується — ціна разова.'),
            Select::make('category_id')->label('Категорія')
                ->relationship('category', 'name')->searchable()->preload(),
        ]);
    }
}
