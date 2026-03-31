<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PackagingResource\Pages;
use App\Models\Packaging;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Tables\Actions;

class PackagingResource extends Resource
{
    protected static ?string $model = Packaging::class;

    protected static ?string $navigationGroup = 'Довідник';
    protected static ?int $navigationSort = 9;
    protected static ?string $navigationLabel = 'Упаковка та госптовари';
    protected static ?string $pluralModelLabel = 'Упаковка та госптовари';
    protected static ?string $modelLabel = 'Упаковка / Госптовар';

    public static function form(Form $form): Form
    {
        return $form->schema([
            TextInput::make('name')
                ->label('Назва')
                ->required()
                ->maxLength(255),
            
            TextInput::make('unit')
                ->label('Одиниця виміру')
                ->default('шт')
                ->required(),

            // 👇 Добавили поле для ввода цены
            TextInput::make('price')
                ->label('Ціна за одиницю (₴)')
                ->numeric()
                ->default(0)
                ->step(0.01),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label('Назва')->searchable()->sortable(),
                TextColumn::make('unit')->label('Од. виміру'),
                TextColumn::make('stock')
                    ->label('Поточний залишок')
                    ->numeric(decimalPlaces: 0)
                    ->badge()
                    ->color(fn ($state) => $state <= 0 ? 'danger' : 'success'),
                
                // 👇 Добавили вывод цены в таблице справочника
                TextColumn::make('price')
                    ->label('Ціна (₴)')
                    ->money('UAH')
                    ->sortable(),
            ])
            ->actions([
                Actions\EditAction::make()->label('')->tooltip('Змінити'),
                Actions\DeleteAction::make()->label('')->tooltip('Видалити'),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPackagings::route('/'),
        ];
    }
}