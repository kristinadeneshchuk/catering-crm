<?php

namespace App\Filament\Resources\ClientResource\RelationManagers;

use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class OrdersRelationManager extends RelationManager
{
    protected static string $relationship = 'orders';
    protected static ?string $title = 'Історія замовлень';
    protected static ?string $icon = 'heroicon-o-clock';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('id')
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->label('№')
                    ->sortable(),

                Tables\Columns\TextColumn::make('start_date')
                    ->label('Період')
                    ->formatStateUsing(fn ($record) => 
                        \Carbon\Carbon::parse($record->start_date)->format('d.m') . ' - ' . 
                        \Carbon\Carbon::parse($record->end_date)->format('d.m') . 
                        ' (' . \Carbon\Carbon::parse($record->start_date)->diffInDays(\Carbon\Carbon::parse($record->end_date)) . ' дн.)'
                    ),

                Tables\Columns\TextColumn::make('status')
                    ->label('Статус')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'new' => 'gray',
                        'active' => 'success',
                        'paused' => 'warning',
                        'completed', 'finished' => 'info',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'new' => 'Новий',
                        'active' => 'Активний',
                        'paused' => 'На паузі',
                        'completed', 'finished' => 'Завершений',
                        default => $state,
                    }),

                Tables\Columns\TextColumn::make('total_price')
                    ->label('Ціна')
                    ->money('UAH')
                    ->sortable(),

                Tables\Columns\IconColumn::make('is_paid')
                    ->label('Оплата')
                    ->boolean()
                    ->trueIcon('heroicon-o-check-circle')
                    ->falseIcon('heroicon-o-x-circle')
                    ->trueColor('success')
                    ->falseColor('danger'),
            ])
            ->headerActions([
                // ❌ Я приховав кнопку створення замовлення тут
                // Tables\Actions\CreateAction::make()
                //    ->label('Нове замовлення')
                //    ->slideOver(),
            ])
            ->actions([
                Tables\Actions\ViewAction::make()
                    ->label('Деталі')
                    ->url(fn ($record) => route('filament.admin.resources.orders.edit', $record)),
                    
                Tables\Actions\DeleteAction::make(),
            ]);
    }
}