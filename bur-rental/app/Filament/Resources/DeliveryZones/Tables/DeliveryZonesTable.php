<?php

namespace App\Filament\Resources\DeliveryZones\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class DeliveryZonesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label('Зона')->searchable(),
                TextColumn::make('city.name')->label('Місто')->sortable(),
                TextColumn::make('price')->label('Вартість')->money('UAH', 1)->sortable(),
                TextColumn::make('eta')->label('Коли привеземо')->toggleable(),
                TextColumn::make('note')->label('Примітка')->toggleable(),
            ])
            ->defaultSort('position')
            ->filters([SelectFilter::make('city')->label('Місто')->relationship('city', 'name')])
            ->recordActions([EditAction::make()])
            ->toolbarActions([BulkActionGroup::make([DeleteBulkAction::make()])]);
    }
}
