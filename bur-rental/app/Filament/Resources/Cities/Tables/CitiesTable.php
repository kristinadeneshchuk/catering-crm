<?php

namespace App\Filament\Resources\Cities\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class CitiesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label('Місто')->searchable()->sortable(),
                TextColumn::make('phone')->label('Телефон'),
                TextColumn::make('branches_count')->label('Філій')->counts('branches')->badge(),
                TextColumn::make('districts_count')->label('Районів')->counts('districts')->badge(),
                TextColumn::make('delivery_zones_count')->label('Зон доставки')->counts('deliveryZones')->badge(),
            ])
            ->defaultSort('position')
            ->recordActions([EditAction::make()])
            ->toolbarActions([BulkActionGroup::make([DeleteBulkAction::make()])]);
    }
}
