<?php

namespace App\Filament\Resources\InventoryResource\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Grouping\Group;
use Illuminate\Database\Eloquent\Builder;

class ItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'items';
    protected static ?string $title = 'Підрахунок товарів';

    public function table(Table $table): Table
    {
        $isCompleted = $this->getOwnerRecord()->status === 'completed';

        return $table
            ->recordTitleAttribute('name')
            ->columns([

                // Статус-крапка
                Tables\Columns\TextColumn::make('dot')
                    ->label('')
                    ->getStateUsing(fn ($record) => '●')
                    ->color(function ($record) {
                        if ($record->actual_qty === null) return 'gray';
                        $diff = round($record->actual_qty - $record->expected_qty, 3);
                        if ($diff > 0) return 'warning';
                        if ($diff < 0) return 'danger';
                        return 'success';
                    })
                    ->extraAttributes(['style' => 'font-size:18px;line-height:1;padding-right:0;']),

                // Назва + група
                Tables\Columns\TextColumn::make('name')
                    ->label('Найменування')
                    ->searchable()
                    ->sortable()
                    ->weight('semibold')
                    ->description(fn ($record) => $record->itemable?->group ?? null),

                // Одиниця
                Tables\Columns\TextColumn::make('unit')
                    ->label('Од.')
                    ->badge()
                    ->color('gray')
                    ->alignCenter(),

                // Очікуваний залишок
                Tables\Columns\TextColumn::make('expected_qty')
                    ->label('За програмою')
                    ->formatStateUsing(fn ($state) => number_format((float)$state, 2, '.', ' '))
                    ->color(fn ($state) => (float)$state < 0 ? 'danger' : 'gray')
                    ->alignEnd(),

                // ПОЛЕ ВВОДУ
                Tables\Columns\TextInputColumn::make('actual_qty')
                    ->label('Факт')
                    ->type('number')
                    ->extraAttributes(['style' => 'min-width:110px;'])
                    ->disabled($isCompleted)
                    ->updateStateUsing(function ($record, $state) {
                        // Зберігаємо з 2 знаками після коми
                        $val = ($state === '' || $state === null)
                            ? null
                            : round((float) str_replace(',', '.', $state), 2);
                        $record->update(['actual_qty' => $val]);
                        $record->inventory->recalculateTotals();
                        $this->dispatch('inventory-stats-updated');
                    }),

                // Різниця
                Tables\Columns\TextColumn::make('difference')
                    ->label('Різниця')
                    ->getStateUsing(function ($record) {
                        if ($record->actual_qty === null) return null;
                        return round($record->actual_qty - $record->expected_qty, 2);
                    })
                    ->formatStateUsing(function ($state) {
                        if ($state === null) return '—';
                        $prefix = (float)$state > 0 ? '+' : '';
                        return $prefix . number_format((float)$state, 2, '.', ' ');
                    })
                    ->badge()
                    ->color(fn ($state) => match(true) {
                        $state === null    => 'gray',
                        (float)$state > 0 => 'success',
                        (float)$state < 0 => 'danger',
                        default           => 'gray',
                    })
                    ->alignEnd(),

                // Сума різниці
                Tables\Columns\TextColumn::make('money_diff')
                    ->label('Сума')
                    ->getStateUsing(function ($record) {
                        if ($record->actual_qty === null) return null;
                        return ($record->actual_qty - $record->expected_qty) * $record->price;
                    })
                    ->formatStateUsing(function ($state) {
                        if ($state === null) return '—';
                        $prefix = (float)$state > 0 ? '+' : '';
                        return $prefix . number_format((float)$state, 2, '.', ' ') . ' ₴';
                    })
                    ->color(fn ($state) => match(true) {
                        $state === null    => 'gray',
                        (float)$state > 0 => 'success',
                        (float)$state < 0 => 'danger',
                        default           => 'gray',
                    })
                    ->weight('bold')
                    ->alignEnd(),
            ])

            // Підсвічування рядків
            ->recordClasses(function ($record) {
                if ($record->actual_qty === null) return null;
                $diff = round($record->actual_qty - $record->expected_qty, 3);
                if ($diff > 0) return 'bg-warning-400/10';
                if ($diff < 0) return 'bg-danger-400/10';
                return 'bg-success-400/10';
            })

            // Групування за категорією
            ->groups([
                Group::make('itemable.group')
                    ->label('Категорія')
                    ->getTitleFromRecordUsing(fn ($record) => $record->itemable?->group ?? 'Без категорії')
                    ->collapsible(),
            ])

            ->filters([
                Filter::make('unfilled')
                    ->label('Незаповнені')
                    ->indicator('Незаповнені')
                    ->query(fn (Builder $query) => $query->whereNull('actual_qty'))
                    ->toggle(),

                Filter::make('with_diff')
                    ->label('З відхиленням')
                    ->indicator('З відхиленням')
                    ->query(fn (Builder $query) => $query->whereNotNull('actual_qty')
                        ->whereRaw('ABS(actual_qty - expected_qty) > 0.001'))
                    ->toggle(),

                Filter::make('surplus')
                    ->label('Надлишок')
                    ->indicator('Надлишок')
                    ->query(fn (Builder $query) => $query->whereNotNull('actual_qty')
                        ->whereRaw('actual_qty > expected_qty + 0.001'))
                    ->toggle(),

                Filter::make('shortage')
                    ->label('Нестача')
                    ->indicator('Нестача')
                    ->query(fn (Builder $query) => $query->whereNotNull('actual_qty')
                        ->whereRaw('actual_qty < expected_qty - 0.001'))
                    ->toggle(),
            ])
            ->filtersLayout(Tables\Enums\FiltersLayout::AboveContent)

            ->headerActions([])
            ->actions([])
            ->bulkActions([])

            ->defaultSort('name', 'asc')
            ->paginated([50, 100, 200, 'all'])
            ->defaultPaginationPageOption(100);
    }
}
