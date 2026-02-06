<?php

namespace App\Filament\Resources\OrderResource\RelationManagers;

use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;

class TransactionsRelationManager extends RelationManager
{
    protected static string $relationship = 'transactions';
    protected static ?string $title = 'Оплата';
    protected static ?string $icon = 'heroicon-o-banknotes';
    protected static ?string $modelLabel = 'транзакцію'; // Однина (для кнопки "Створити ...")
    protected static ?string $pluralModelLabel = 'транзакції'; // Множина (для "Не знайдено ...")

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Select::make('type')
                    ->label('Операція')
                    ->options([
                        'income' => 'Оплата замовлення (+)',
                        'refund' => 'Повернення коштів (-)',
                    ])
                    ->default('income')
                    ->required(),

                // === ЗМІНА ТУТ: Вибір з реальних рахунків ===
                Select::make('account_id')
                    ->label('Рахунок / Каса')
                    ->relationship('account', 'name') // Підтягує назви з таблиці accounts
                    ->searchable()
                    ->preload()
                    ->required()
                    ->placeholder('Оберіть рахунок (Готівка, Mono...)'),

                TextInput::make('amount')
                    ->label('Сума')
                    ->numeric()
                    ->prefix('₴')
                    ->required(),

                DatePicker::make('date')
                    ->label('Дата платежу')
                    ->default(now())
                    ->required(),

                Textarea::make('comment')
                    ->label('Коментар')
                    ->columnSpanFull(),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')->label('№'),
                
                // === ЗМІНА ТУТ: Показуємо назву рахунку ===
                Tables\Columns\TextColumn::make('account.name')
                    ->label('Рахунок')
                    ->badge()
                    ->color('gray') // Можна змінити колір, якщо у рахунків є поле кольору
                    ->sortable(),

                Tables\Columns\TextColumn::make('amount')
                    ->label('Оплата')
                    ->money('UAH')
                    ->color(fn ($record) => $record->type === 'income' ? 'success' : 'danger')
                    ->prefix(fn ($record) => $record->type === 'income' ? '+' : '-'),

                Tables\Columns\TextColumn::make('date')
                    ->label('Дата')
                    ->date('d.m.Y'),
                
                Tables\Columns\TextColumn::make('comment')
                    ->label('Коментар')
                    ->limit(30),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make()
                    ->label('Додати нову операцію')
                    ->modalHeading('Нова оплата')
                    ->mutateFormDataUsing(function (array $data) {
                        $data['user_id'] = auth()->id();
                        return $data;
                    }),
            ])
            ->actions([
                Tables\Actions\DeleteAction::make(),
            ]);
    }
}