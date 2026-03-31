<?php

namespace App\Filament\Resources;

use App\Filament\Resources\WarehouseResource\Pages;
use App\Models\Warehouse;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Tables\Actions;

class WarehouseResource extends Resource
{
    protected static ?string $model = Warehouse::class;

    protected static ?string $navigationGroup = 'Довідник';
    protected static ?int $navigationSort = 8;
    protected static ?string $navigationLabel = 'Склади';
    protected static ?string $pluralModelLabel = 'Склади';
    protected static ?string $modelLabel = 'Склад';

    public static function form(Form $form): Form
    {
        return $form->schema([
            TextInput::make('name')
                ->label('Назва складу')
                ->placeholder('Наприклад: Склад продуктів')
                ->required()
                ->maxLength(255)
                ->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')->label('ID')->sortable(),
                TextColumn::make('name')->label('Назва')->searchable(),
            ])
            ->actions([
                Actions\EditAction::make()->label('')->tooltip('Змінити'),
                Actions\DeleteAction::make()->label('')->tooltip('Видалити'),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListWarehouses::route('/'),
        ];
    }
}