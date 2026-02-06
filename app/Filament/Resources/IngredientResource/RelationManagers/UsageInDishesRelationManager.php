<?php

namespace App\Filament\Resources\IngredientResource\RelationManagers;

use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class UsageInDishesRelationManager extends RelationManager
{
    // Назва зв'язку в моделі Ingredient
    protected static string $relationship = 'dishes'; 

    // Заголовок секції (як на скріншоті)
    protected static ?string $title = 'Цей інгредієнт бере участь у стравах:';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Назва страви / ПФ')
                    ->searchable()
                    ->sortable()
                    ->description(fn ($record) => $record->is_semi_finished ? 'Напівфабрикат' : 'Готова страва'),

                Tables\Columns\TextColumn::make('group')
                    ->label('Група')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'ПФ' => 'warning',
                        'Десерти' => 'success',
                        default => 'gray',
                    }),

                Tables\Columns\TextColumn::make('base_weight_g')
                    ->label('Вихід (г)')
                    ->suffix(' г'),
            ])
            ->filters([
                // Можна додати фільтр, щоб бачити тільки ПФ, де використовується яблуко
            ])
            ->headerActions([
                // Прибираємо CreateAction, бо страви створюються в DishResource
            ])
            ->actions([
                // Кнопка швидкого переходу до редагування техкарти цієї страви
                Tables\Actions\EditAction::make()
                    ->url(fn ($record): string => route('filament.admin.resources.dishes.edit', ['record' => $record])),
            ])
            ->bulkActions([]);
    }
}