<?php

namespace App\Filament\Resources;

use App\Filament\Resources\SettingResource\Pages;
use App\Models\Setting;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class SettingResource extends Resource
{
    protected static ?string $model = Setting::class;
    protected static ?string $navigationGroup = 'Система';
    protected static ?int $navigationSort = 1;
    protected static ?string $navigationLabel = 'Налаштування бізнесу';
    protected static ?string $modelLabel = 'Налаштування';

    public static function canViewAny(): bool
    {
        return auth()->user()->role === 'admin'; // Доступ тільки для тебе
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Глобальні параметри')
                    ->schema([
                        Forms\Components\TextInput::make('key')
                            ->label('Параметр (Ключ)')
                            ->disabled() // Забороняємо змінювати ключ, щоб не зламати логіку в коді
                            ->dehydrated(), 

                        Forms\Components\TextInput::make('value')
                            ->label('Значення')
                            ->required()
                            ->helperText('Для параметра "menu_cycle_days" вкажіть кількість днів циклу (напр. 24 або 30).'),
                    ])->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('key')
                    ->label('Назва налаштування')
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'menu_cycle_days' => 'Тривалість циклу меню (днів)',
                        default => $state,
                    }),
                Tables\Columns\TextColumn::make('value')
                    ->label('Поточне значення')
                    ->badge()
                    ->color('success'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSettings::route('/'),
            'edit' => Pages\EditSetting::route('/{record}/edit'),
        ];
    }
}