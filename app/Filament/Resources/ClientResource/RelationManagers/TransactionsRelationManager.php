<?php

namespace App\Filament\Resources\ClientResource\RelationManagers;

use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class TransactionsRelationManager extends RelationManager
{
    protected static string $relationship = 'transactions';
    
    // Залишаємо назву, але можна додати помітку
    protected static ?string $title = 'Історія оплат (Тимчасово недоступно)';
    protected static ?string $icon = 'heroicon-o-lock-closed';

    public function form(Form $form): Form
    {
        // Порожня форма
        return $form->schema([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                // Виводимо лише повідомлення замість даних
                Tables\Columns\TextColumn::make('placeholder')
                    ->label('Статус')
                    ->default('Функціонал перераховується. Зверніться до адміністратора.')
                    ->italic(),
            ])
            ->headerActions([]) // Прибираємо кнопку "Створити"
            ->actions([])       // Прибираємо кнопки редагування/видалення
            ->bulkActions([]);  // Прибираємо масові дії
    }
}