<?php

namespace App\Filament\Resources\Extras\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class ExtrasTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label('Витратник')->searchable()->sortable(),
                TextColumn::make('sub')->label('Підпис')->toggleable(),
                TextColumn::make('price')->label('Ціна')->money('UAH', 1)->sortable(),
                TextColumn::make('category.name')->label('Категорія')->placeholder('—'),
            ])
            ->defaultSort('name')
            ->filters([
                SelectFilter::make('category')->label('Категорія')->relationship('category', 'name'),
            ])
            ->recordActions([EditAction::make()])
            ->toolbarActions([BulkActionGroup::make([DeleteBulkAction::make()])]);
    }
}
