<?php

namespace App\Filament\Resources\Districts\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class DistrictsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label('Район')->searchable()->sortable(),
                TextColumn::make('city.name')->label('Місто')->sortable(),
                IconColumn::make('intro')->label('Є текст')->boolean()
                    ->state(fn ($record) => filled($record->intro)),
                TextColumn::make('branches_count')->label('Філій')->counts('branches')->badge(),
            ])
            ->filters([SelectFilter::make('city')->label('Місто')->relationship('city', 'name')])
            ->recordActions([EditAction::make()])
            ->toolbarActions([BulkActionGroup::make([DeleteBulkAction::make()])]);
    }
}
