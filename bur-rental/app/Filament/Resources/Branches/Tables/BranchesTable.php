<?php

namespace App\Filament\Resources\Branches\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class BranchesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label('Філія')->searchable()->sortable(),
                TextColumn::make('city.name')->label('Місто')->sortable(),
                TextColumn::make('address')->label('Адреса')->searchable(),
                TextColumn::make('hours')->label('Графік')->toggleable(),
                TextColumn::make('products_count')->label('Позицій')->counts('products')->badge(),
                TextColumn::make('rating')->label('Google')->toggleable(),
            ])
            ->defaultSort('position')
            ->filters([
                SelectFilter::make('city')->label('Місто')->relationship('city', 'name'),
            ])
            ->recordActions([EditAction::make()])
            ->toolbarActions([BulkActionGroup::make([DeleteBulkAction::make()])]);
    }
}
