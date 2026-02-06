<?php

namespace App\Filament\Resources;

use App\Filament\Resources\AccountResource\Pages;
use App\Models\Account;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Forms\Components\Card;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\IconColumn;

class AccountResource extends Resource
{
    protected static ?string $model = Account::class;

    protected static ?string $navigationLabel = 'Рахунки';
    protected static ?string $modelLabel = 'Рахунок';
    protected static ?string $pluralModelLabel = 'Рахунки';
    protected static ?string $navigationGroup = 'Довідник';
    protected static ?int $navigationSort = 7;
    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Card::make()->schema([
                    TextInput::make('name')
                        ->label('Назва рахунку')
                        ->required()
                        ->placeholder('Напр. Розрахунковий рахунок')
                        ->columnSpanFull(),

                    Select::make('type')
                        ->label('Тип рахунку')
                        ->options([
                            'cash' => 'Готівка (Каса)',
                            'online' => 'Онлайн оплата',
                            'card' => 'Картка',
                        ])
                        ->required(),

                    TextInput::make('balance')
                        ->label('Поточний баланс')
                        ->numeric()
                        ->prefix('₴')
                        ->default(0)
                        ->required(),

                    Toggle::make('is_default')
                        ->label('Рахунок за замовчуванням для оплат')
                        ->onColor('success')
                        ->offColor('gray')
                        ->columnSpanFull(),
                ])->columns(2)
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')
                    ->label('№')
                    ->sortable(),

                TextColumn::make('name')
                    ->label('Назва рахунку')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),

                TextColumn::make('type')
                    ->label('Тип')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'cash' => 'warning',   // Жовтий для готівки
                        'online' => 'success', // Зелений для онлайн
                        'card' => 'info',      // Синій для картки
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'cash' => 'Готівка',
                        'online' => 'Онлайн',
                        'card' => 'Картка',
                        default => $state,
                    }),

                TextColumn::make('balance')
                    ->label('Баланс')
                    ->money('UAH') // Автоматично додасть знак ₴ і пробіли
                    ->sortable(),

                IconColumn::make('is_default')
                    ->label('За замовч.')
                    ->boolean()
                    ->trueIcon('heroicon-o-check-circle')
                    ->falseIcon('heroicon-o-x-circle')
                    ->alignCenter(),
            ])
            ->defaultSort('id', 'asc')
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\DeleteBulkAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAccounts::route('/'),
            'create' => Pages\CreateAccount::route('/create'),
            'edit' => Pages\EditAccount::route('/{record}/edit'),
        ];
    }
}