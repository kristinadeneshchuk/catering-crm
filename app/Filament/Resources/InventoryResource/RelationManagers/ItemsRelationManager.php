<?php

namespace App\Filament\Resources\InventoryResource\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class ItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'items';
    protected static ?string $title = 'Підрахунок товарів';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Товар / Інгредієнт')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('unit')
                    ->label('Од. вим.')
                    ->color('gray'),

                Tables\Columns\TextColumn::make('expected_qty')
                    ->label('В прогр. (План)')
                    ->numeric(3)
                    ->badge()
                    ->color('info'),

                // 🔥 МАГІЯ: Поле вводу прямо в таблиці!
                Tables\Columns\TextInputColumn::make('actual_qty')
                    ->label('Фактичний залишок')
                    ->type('number')
                    ->extraAttributes(['step' => 'any'])
                    ->disabled(fn () => $this->getOwnerRecord()->status === 'completed') // Блокуємо, якщо проведено
                    ->updateStateUsing(function ($record, $state) {
                        $val = $state === '' ? null : (float) str_replace(',', '.', $state);
                        $record->update(['actual_qty' => $val]);
                        
                        // Відразу перераховуємо загальну суму в документі!
                        $record->inventory->recalculateTotals(); 
                    }),

                Tables\Columns\TextColumn::make('difference')
                    ->label('Різниця (кількість)')
                    ->getStateUsing(function ($record) {
                        if ($record->actual_qty === null) return null;
                        return round($record->actual_qty - $record->expected_qty, 3);
                    })
                    ->color(fn ($state) => $state > 0 ? 'success' : ($state < 0 ? 'danger' : 'gray'))
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('money_diff')
                    ->label('Сума різниці')
                    ->getStateUsing(function ($record) {
                        if ($record->actual_qty === null) return null;
                        return ($record->actual_qty - $record->expected_qty) * $record->price;
                    })
                    ->money('UAH')
                    ->color(fn ($state) => $state > 0 ? 'success' : ($state < 0 ? 'danger' : 'gray')),
            ])
            ->filters([])
            ->headerActions([])
            ->actions([])
            ->bulkActions([])
            ->paginated([50, 100, 200, 'all']) // Зручно для великого списку
            ->defaultPaginationPageOption(100);
    }
}