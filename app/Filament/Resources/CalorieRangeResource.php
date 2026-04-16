<?php

namespace App\Filament\Resources;

use App\Traits\RestrictCookAccess;

use App\Filament\Resources\CalorieRangeResource\Pages;
use App\Models\CalorieRange;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Section;
use Filament\Tables\Columns\TextColumn;

class CalorieRangeResource extends Resource
{
    use RestrictCookAccess;
    protected static ?string $model = CalorieRange::class;

    // ГРУПУВАННЯ ТА СОРТУВАННЯ
    protected static ?string $navigationGroup = 'Довідник';
    protected static ?int $navigationSort = 5; // Позиція всередині групи
    protected static ?string $navigationLabel = 'Діапазони калорій';
    protected static ?string $pluralModelLabel = 'Діапазони калорій';
    protected static ?string $modelLabel = 'Діапазон';

    /**
     * 🔒 ЗАХИСТ: Тільки Адмін та Менеджер. 
     * Це приховає розділ від Кухаря.
     */
    public static function canViewAny(): bool
    {
        return auth()->user()->role === 'admin' || auth()->user()->role === 'manager';
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('Параметри діапазону')
                    ->description('Визначте межі калорійності для цієї групи тарифів')
                    ->schema([
                        TextInput::make('name')
                            ->label('Назва')
                            ->placeholder('Напр: STRONG 2400-2500')
                            ->required()
                            ->maxLength(255),
                        
                        TextInput::make('min_kcal')
                            ->label('Мінімальна ккал')
                            ->numeric()
                            ->required(),
                            
                        TextInput::make('max_kcal')
                            ->label('Максимальна ккал')
                            ->numeric()
                            ->required(),
                    ])->columns(3),

                Section::make('Матриця цін')
                    ->description('Встановіть вартість одного дня харчування для кожного тарифного плану')
                    ->schema([
                        Repeater::make('prices')
                            ->relationship('prices') // Потребує зв'язку HasMany в моделі CalorieRange
                            ->schema([
                                Select::make('tariff_id')
                                    ->label('Тариф')
                                    ->relationship('tariff', 'name') // Залишаємо зв'язок
                                    /**
                                     * 🔥 ОНОВЛЕНО: Тепер назва проєкту тягнеться динамічно з бази даних!
                                     * Формат буде: "Назва тарифу (Назва проєкту)"
                                     */
                                    ->getOptionLabelFromRecordUsing(fn ($record) => 
                                        "{$record->name} (" . ($record->projectData?->name ?? $record->project) . ")"
                                    )
                                    ->required()
                                    ->searchable()
                                    ->preload(),
                                
                                TextInput::make('price_per_day')
                                    ->label('Ціна за 1 день')
                                    ->numeric()
                                    ->prefix('₴')
                                    ->required(),
                            ])
                            ->columns(2)
                            ->grid(2)
                            ->label('')
                            ->createItemButtonLabel('Додати тариф та ціну')
                    ])
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Назва діапазону')
                    ->sortable()
                    ->searchable(),
                    
                TextColumn::make('min_kcal')
                    ->label('Мін. ккал')
                    ->sortable(),
                    
                TextColumn::make('max_kcal')
                    ->label('Макс. ккал')
                    ->sortable(),

                // Колонка показує, для скількох тарифів уже встановлена ціна
                TextColumn::make('prices_count')
                    ->label('Налаштовано цін')
                    ->counts('prices')
                    ->badge()
                    ->color('success'),
            ])
            ->filters([
                // Тут можна додати фільтр за межами калорій
            ])
            ->actions([
                Tables\Actions\EditAction::make()->label('')->tooltip('Змінити'),
                Tables\Actions\DeleteAction::make()->label('')->tooltip('Видалити'),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            // Якщо матриця цін стане дуже великою, можна винести її в RelationManager
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCalorieRanges::route('/'),
            'create' => Pages\CreateCalorieRange::route('/create'),
            'edit' => Pages\EditCalorieRange::route('/{record}/edit'),
        ];
    }
}