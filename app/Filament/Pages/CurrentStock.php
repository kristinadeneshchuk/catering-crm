<?php

namespace App\Filament\Pages;

use App\Models\Ingredient;
use App\Models\Packaging;
use App\Models\Dish;
use Filament\Pages\Page;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Table;
use Filament\Tables\Columns\TextColumn;

class CurrentStock extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $navigationGroup = 'Склад';
    protected static ?int $navigationSort = 2; 
    protected static ?string $title = 'Поточний залишок';
    protected static string $view = 'filament.pages.current-stock';

    public string $activeTab = 'ingredients';

    public function updatedActiveTab()
    {
        $this->resetTable();
    }

    public function table(Table $table): Table
    {
        if ($this->activeTab === 'packaging') {
            $query = Packaging::query();
        } 
        // 🟡 ЗАКОМЕНТОВАНО: ДЛЯ НАПІВФАБРИКАТІВ
        // elseif ($this->activeTab === 'half_dishes') {
        //     // 🔥 ВИКОРИСТОВУЄМО ВАШУ КОЛОНКУ
        //     $query = Dish::query()->where('is_semi_finished', true);
        // } 
        else {
            $query = Ingredient::query();
        }

        return $table
            ->query($query)
            ->columns([
                TextColumn::make('id')
                    ->label('№')
                    ->sortable()
                    ->width(50),

                TextColumn::make('name')
                    ->label('Найменування')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('stock')
                    ->label('Поточна кількість')
                    ->badge()
                    ->color(fn ($state) => $state <= 0 ? 'danger' : 'success')
                    ->formatStateUsing(function ($state, $record) {
                        if ($this->activeTab === 'packaging') {
                            return round((float)$state) . ' шт'; 
                        }
                        
                        // 🟡 ЗАКОМЕНТОВАНО: ДЛЯ НАПІВФАБРИКАТІВ
                        // if ($this->activeTab === 'half_dishes') {
                        //     return (float)$state . ' кг';
                        // }
                        
                        return (float)$state . ' ' . ($record->unit ?? 'кг');
                    })
                    ->sortable(),

                TextColumn::make('total_cost')
                    ->label('Сум. ціна')
                    ->getStateUsing(function ($record) {
                        
                        // 🟢 ДЛЯ ІНГРЕДІЄНТІВ:
                        // Множимо актуальний поточний залишок на РЕАЛЬНУ середню ціну закупівель
                        if ($this->activeTab === 'ingredients') {
                            $stock = (float) ($record->stock ?? 0);
                            
                            // 🔥 ПРАВКА ТУТ: Беремо average_price замість price_per_kg
                            $price = (float) ($record->average_price ?? 0); 
                            
                            $multiplier = in_array($record->unit, ['г', 'мл']) ? 0.001 : 1;
                            
                            return number_format($stock * $multiplier * $price, 2, '.', '') . ' ₴';
                        }
                        
                        // 🟡 ЗАКОМЕНТОВАНО: ДЛЯ НАПІВФАБРИКАТІВ:
                        // if ($this->activeTab === 'half_dishes') {
                        //     $stockKg = (float) ($record->stock ?? 0);
                        //     $recipeCost = (float) $record->total_cost;
                        //     $recipeWeightGrams = (float) $record->output_weight;

                        //     $total = $recipeWeightGrams > 0 ? ($recipeCost / $recipeWeightGrams) * 1000 * $stockKg : 0;
                        //     return number_format($total, 2, '.', '') . ' ₴';
                        // }

                        // 📦 ДЛЯ ПАКУВАННЯ
                        if ($this->activeTab === 'packaging') {
                            $stock = (float) ($record->stock ?? 0);
                            $price = (float) ($record->price ?? 0); // Беремо ціну, яку ми додали
                            
                            return number_format($stock * $price, 2, '.', '') . ' ₴'; 
                        }
                    })
                    ->color(fn ($state) => (float)$state < 0 ? 'danger' : 'gray')
                    ->weight('bold'),
            ])
            ->defaultSort('name', 'asc');
    }
}