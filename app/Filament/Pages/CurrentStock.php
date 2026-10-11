<?php

namespace App\Filament\Pages;

use App\Models\Ingredient;
use App\Models\Packaging;
use App\Traits\AllowCookAccess;
use Filament\Pages\Page;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Table;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Grouping\Group;

class CurrentStock extends Page implements HasTable
{
    use InteractsWithTable, AllowCookAccess;

    protected static ?string $navigationGroup = 'Склад';
    protected static ?int $navigationSort = 2;
    protected static ?string $title = 'Поточний залишок';
    protected static string $view = 'filament.pages.current-stock';

    public string $activeTab    = 'ingredients';
    public string $stockFilter  = 'all'; // all | ok | deficit | zero

    public function updatedActiveTab(): void
    {
        $this->stockFilter = 'all';
        $this->resetTable();
    }

    public function updatedStockFilter(): void
    {
        $this->resetTable();
    }

    // ── Статистика (для плашок вверху) ──────────────────────────────
    public function getStats(): array
    {
        if ($this->activeTab === 'packaging') {
            $q = Packaging::query();
            return [
                'total'   => $q->count(),
                'ok'      => (clone $q)->where('stock', '>', 0)->count(),
                'deficit' => (clone $q)->where('stock', '<', 0)->count(),
                'zero'    => (clone $q)->where('stock', '=', 0)->count(),
                'value'   => Packaging::get()->sum(fn ($p) => max(0, (float)$p->stock * (float)$p->price)),
            ];
        }

        return [
            'total'   => Ingredient::count(),
            'ok'      => Ingredient::where('stock', '>', 0)->count(),
            'deficit' => Ingredient::where('stock', '<', 0)->count(),
            'zero'    => Ingredient::where('stock', '=', 0)->count(),
            // Ціна — як у собівартості (costPerGram): для карток у «г» середня ціна
            // буває за кг, і «залишок × average_price» давав ікру на 329 000 ₴.
            'value'   => (function () {
                Ingredient::preloadAveragePrices();

                return Ingredient::get()->sum(fn ($i) => max(0, $i->stockValue()));
            })(),
        ];
    }

    public function table(Table $table): Table
    {
        $query = $this->activeTab === 'packaging'
            ? Packaging::query()
            : Ingredient::query();

        // Ціни для «Ціна / од.» і «Вартість» — одним запитом, а не по рядку.
        if ($this->activeTab !== 'packaging') Ingredient::preloadAveragePrices();

        // Фільтр по статусу залишку
        $query->when($this->stockFilter === 'ok',      fn ($q) => $q->where('stock', '>', 0))
              ->when($this->stockFilter === 'deficit',  fn ($q) => $q->where('stock', '<', 0))
              ->when($this->stockFilter === 'zero',     fn ($q) => $q->where('stock', '=', 0));

        $columns = [
            TextColumn::make('name')
                ->label('Найменування')
                ->searchable()
                ->sortable()
                ->weight('semibold'),

            TextColumn::make('group')
                ->label('Група')
                ->badge()
                ->color('gray')
                ->sortable()
                ->toggleable()
                ->visible($this->activeTab === 'ingredients'),

            TextColumn::make('stock')
                ->label('Залишок')
                ->sortable()
                ->alignEnd()
                ->formatStateUsing(function ($state, $record) {
                    $val  = (float)$state;
                    $unit = $this->activeTab === 'packaging' ? 'шт' : ($record->unit ?? 'кг');
                    return number_format(abs($val), $val == (int)$val ? 0 : 3, '.', ' ') . ' ' . $unit;
                })
                ->color(fn ($state) => match(true) {
                    (float)$state <  0 => 'danger',
                    (float)$state == 0 => 'gray',
                    default            => 'success',
                })
                ->badge(),

            TextColumn::make('average_price')
                ->label('Ціна / од.')
                ->alignEnd()
                ->visible($this->activeTab === 'ingredients')
                ->getStateUsing(fn ($record) => $record->pricePerStockUnit())
                ->formatStateUsing(fn ($state, $record) => $state
                    ? number_format((float)$state, in_array($record->unit, ['г', 'мл'], true) ? 4 : 2, '.', ' ') . ' ₴/' . ($record->unit ?: 'кг')
                    : '—')
                ->color('gray')
                ->toggleable(),

            TextColumn::make('total_cost')
                ->label('Вартість')
                ->alignEnd()
                ->getStateUsing(function ($record) {
                    if ($this->activeTab === 'packaging') {
                        $val = (float)$record->stock * (float)$record->price;
                    } else {
                        $val = $record->stockValue();
                    }
                    return $val;
                })
                ->formatStateUsing(fn ($state) => number_format((float)$state, 2, '.', ' ') . ' ₴')
                ->color(fn ($state) => (float)$state < 0 ? 'danger' : 'gray')
                ->weight('bold'),
        ];

        $tableBuilder = $table
            ->query($query)
            ->columns($columns)
            ->defaultSort('name', 'asc')
            ->striped()
            ->paginated([25, 50, 100, 'all']);

        if ($this->activeTab === 'ingredients') {
            $tableBuilder = $tableBuilder->groups([
                Group::make('group')->label('Група')->collapsible(),
            ]);
        }

        return $tableBuilder;
    }
}
