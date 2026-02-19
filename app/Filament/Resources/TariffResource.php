<?php

namespace App\Filament\Resources;

use App\Filament\Resources\TariffResource\Pages;
use App\Models\Tariff;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope; // 🔥 1. Імпорт для роботи з видаленими

class TariffResource extends Resource
{
    protected static ?string $model = Tariff::class;
    protected static ?string $navigationGroup = 'Довідник';
    protected static ?int $navigationSort = 5;
    protected static ?string $navigationLabel = 'Категорії тарифів';
    protected static ?string $pluralModelLabel = 'Категорії тарифів';
    protected static ?string $modelLabel = 'Категорія';

    public static function canViewAny(): bool
    {
        return auth()->user()->role === 'admin' || auth()->user()->role === 'manager';
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Параметри тарифу')
                    ->description('Визначте назву тарифного плану для розрахунку ціни за день.')
                    ->schema([
                        // Вибір бренду
                        Forms\Components\Select::make('project')
                            ->label('Проєкт')
                            ->options([
                                'avocado_food' => 'AFood', // Изменено название
                                'u_fit' => 'U-FIT',
                                'level_up' => 'LevelUp',   // Добавлен новый проект
                            ])
                            ->required()
                            ->native(false)
                            ->default('avocado_food')
                            ->prefixIcon('heroicon-o-building-storefront'),

                        // Назва тарифу
                        Forms\Components\TextInput::make('name')
                            ->label('Назва тарифу')
                            ->placeholder('Наприклад: Від 7 днів')
                            ->required(),

                    ])->columns(2),

                Forms\Components\Section::make('Статус')
                    ->schema([
                        Forms\Components\Toggle::make('is_active')
                            ->label('Доступний для вибору')
                            ->default(true)
                            ->onColor('success'),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('project')
                    ->label('Проєкт')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'avocado_food' => 'success',
                        'u_fit' => 'info',
                        'level_up' => 'warning', // Добавлен цвет для нового проекта (желтый/оранжевый)
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'avocado_food' => 'AFood', // Изменено название в таблице
                        'u_fit' => 'U-FIT',
                        'level_up' => 'LevelUp',   // Добавлено отображение в таблице
                        default => $state,
                    })
                    ->sortable(),

                Tables\Columns\TextColumn::make('name')
                    ->label('Назва категорії')
                    ->sortable()
                    ->searchable(),

                Tables\Columns\IconColumn::make('is_active')
                    ->label('Активний')
                    ->boolean(),
            ])
            ->defaultSort('project')
            ->filters([
                // 🔥 2. Фільтр для перегляду видалених записів (Кошик)
                Tables\Filters\TrashedFilter::make(),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(), // Звичайне (м'яке) видалення
                
                // 🔥 3. Додаткові дії для видалених записів
                Tables\Actions\ForceDeleteAction::make(), // Видалити назавжди
                Tables\Actions\RestoreAction::make(),     // Відновити з кошика
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                    Tables\Actions\ForceDeleteBulkAction::make(), // Масове видалення назавжди
                    Tables\Actions\RestoreBulkAction::make(),     // Масове відновлення
                ]),
            ]);
    }

    // 🔥 4. Додаємо цей метод, щоб адмінка бачила видалені записи
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->withoutGlobalScopes([
                SoftDeletingScope::class,
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTariffs::route('/'),
            'create' => Pages\CreateTariff::route('/create'),
            'edit' => Pages\EditTariff::route('/{record}/edit'),
        ];
    }
}