<?php

namespace App\Filament\Resources\Products\Tables;

use App\Models\Product;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class ProductsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                IconColumn::make('published')
                    ->label('')
                    ->boolean()
                    ->trueIcon('heroicon-o-check-circle')
                    ->falseIcon('heroicon-o-pencil-square')
                    ->trueColor('success')
                    ->falseColor('warning')
                    ->tooltip(fn ($record) => $record->published ? 'Опубліковано' : 'Чернетка з імпорту — на сайті не видно'),

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

                Filter::make('drafts')
                    ->label('Чернетки з імпорту')
                    ->query(fn (Builder $q) => $q->where('published', false)),
            ])
            ->recordActions([
                Action::make('publish')
                    ->label('Опублікувати')
                    ->icon('heroicon-o-eye')
                    ->visible(fn (Product $r) => ! $r->published)
                    ->requiresConfirmation()
                    ->modalDescription('Перевірте ціну, категорію і заставу — після публікації позиція одразу на вітрині.')
                    ->action(fn (Product $r) => $r->update(['published' => true])),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('publish-many')
                        ->label('Опублікувати вибрані')
                        ->icon('heroicon-o-eye')
                        ->requiresConfirmation()
                        ->action(fn (Collection $records) => $records->each->update(['published' => true])),
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
