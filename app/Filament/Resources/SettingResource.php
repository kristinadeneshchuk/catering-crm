<?php

namespace App\Filament\Resources;

use App\Filament\Resources\SettingResource\Pages;
use App\Models\Setting;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class SettingResource extends Resource
{
    protected static ?string $model = Setting::class;
    protected static ?string $navigationGroup = 'Система';
    protected static ?int $navigationSort = 1;
    protected static ?string $navigationLabel = 'Налаштування бізнесу';
    protected static ?string $modelLabel = 'Налаштування';
    protected static ?string $pluralModelLabel = 'Налаштування';

    public static function canViewAny(): bool
    {
        return auth()->user()->role === 'admin';
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canDelete(\Illuminate\Database\Eloquent\Model $record): bool
    {
        return false;
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Редагування параметра')
                    ->schema([
                        // 1. НАЗВА (Тільки для читання)
                        Forms\Components\TextInput::make('key')
                            ->label('Параметр')
                            ->formatStateUsing(fn (?string $state): string => match ($state) {
                                'menu_cycle_days' => 'Тривалість циклу (днів)',
                                'menu_cycle_start_date' => 'Дата початку циклу',
                                default => (string)$state,
                            })
                            ->disabled()
                            // 🔥 ВАЖЛИВО: false означає "Не зберігати це поле в базу".
                            // Це захистить від помилки Duplicate entry.
                            ->dehydrated(false),

                        // 2. ЗНАЧЕННЯ (Число)
                        Forms\Components\TextInput::make('value_days')
                            ->label('Кількість днів')
                            ->statePath('value')
                            ->required()
                            ->numeric()
                            ->visible(fn ($record) => $record && $record->key === 'menu_cycle_days'),

                        // 3. ЗНАЧЕННЯ (Дата)
                        Forms\Components\DatePicker::make('value_date')
                            ->label('Дата початку')
                            ->statePath('value')
                            ->required()
                            ->displayFormat('d.m.Y')
                            ->format('Y-m-d')
                            ->visible(fn ($record) => $record && $record->key === 'menu_cycle_start_date'),
                    ])->columns(1),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(function (Builder $query) {
                // Показуємо тільки наші два налаштування
                return $query->whereIn('key', ['menu_cycle_days', 'menu_cycle_start_date']);
            })
            ->columns([
                Tables\Columns\TextColumn::make('key')
                    ->label('Налаштування')
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'menu_cycle_days' => 'Тривалість циклу меню (днів)',
                        'menu_cycle_start_date' => 'Дата початку відліку циклу',
                        default => $state,
                    })
                    ->sortable(),

                Tables\Columns\TextColumn::make('value')
                    ->label('Значення')
                    ->badge()
                    ->color('info')
                    ->formatStateUsing(function ($state, $record) {
                        if ($record->key === 'menu_cycle_start_date') {
                            return \Carbon\Carbon::parse($state)->format('d.m.Y');
                        }
                        return $state;
                    }),
            ])
            ->actions([
                Tables\Actions\EditAction::make()->modalHeading('Змінити'),
            ])
            ->bulkActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSettings::route('/'),
            'edit' => Pages\EditSetting::route('/{record}/edit'),
        ];
    }
}