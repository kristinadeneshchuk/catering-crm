<?php

namespace App\Filament\Resources\Bookings\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Позиції броні. Редагувати їх руками не даємо: суми зафіксовані на момент
 * бронювання і перераховуються тільки при прийманні техніки.
 */
class ItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'items';

    protected static ?string $title = 'Позиції';

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')->label('Позиція'),
                TextColumn::make('qty')->label('К-сть'),
                TextColumn::make('days')->label('Днів'),
                TextColumn::make('price_per_day')->label('₴/день')->money('UAH', 1),
                TextColumn::make('total')->label('Сума')->money('UAH', 1),
                TextColumn::make('deposit')->label('Застава')->money('UAH', 1),
            ]);
    }
}
