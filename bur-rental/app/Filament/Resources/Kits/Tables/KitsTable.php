<?php

namespace App\Filament\Resources\Kits\Tables;

use App\Models\Kit;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class KitsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label('Комплект')->searchable()->sortable(),
                TextColumn::make('task')->label('Задача')->toggleable(),
                TextColumn::make('items_count')->label('Позицій')->counts('items')->badge(),
                TextColumn::make('discount_percent')->label('Знижка')->suffix('%'),
                TextColumn::make('week_price')
                    ->label('Тиждень')
                    ->state(fn (Kit $r) => $r->priceFor(7))
                    ->money('UAH', 1)
                    ->description('ціна за день при оренді від 7 днів'),
                TextColumn::make('position')->label('Порядок')->sortable(),
            ])
            ->defaultSort('position')
            ->recordActions([EditAction::make()])
            ->toolbarActions([BulkActionGroup::make([DeleteBulkAction::make()])]);
    }
}
