<?php

namespace App\Filament\Resources\Products\Tables;

use App\Models\Product;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class ProductsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Модель')
                    ->description(fn (Product $r) => $r->brand?->name.' · '.$r->sku)
                    ->searchable(['name', 'sku'])
                    ->sortable(),

                TextColumn::make('category.name')
                    ->label('Категорія')
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('base_price')
                    ->label('Базовий тариф')
                    ->money('UAH', 1)
                    ->sortable(),

                // «Від» — те, що клієнт бачить у картці: найдешевший рівень сходинки.
                TextColumn::make('min_price')
                    ->label('Від')
                    ->state(fn (Product $r) => $r->min_price)
                    ->money('UAH', 1),

                TextColumn::make('deposit')
                    ->label('Застава')
                    ->money('UAH', 1)
                    ->toggleable(),

                TextColumn::make('branches_count')
                    ->label('Філій')
                    ->counts('branches')
                    ->badge(),

                TextColumn::make('popularity')
                    ->label('Популярність')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('popularity', 'desc')
            ->filters([
                SelectFilter::make('category')
                    ->label('Категорія')
                    ->relationship('category', 'name')
                    ->searchable()->preload(),

                SelectFilter::make('brand')
                    ->label('Бренд')
                    ->relationship('brand', 'name')
                    ->searchable()->preload(),

                SelectFilter::make('branch')
                    ->label('Є у філії')
                    ->relationship('branches', 'name'),
            ])
            ->recordActions([EditAction::make()])
            ->toolbarActions([
                BulkActionGroup::make([DeleteBulkAction::make()]),
            ]);
    }
}
