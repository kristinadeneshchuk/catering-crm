<?php

namespace App\Filament\Resources;

use App\Filament\Resources\DailyMenuResource\Pages;
use App\Models\DailyMenu;
use App\Models\Setting;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;

class DailyMenuResource extends Resource
{
    protected static ?string $model = DailyMenu::class;
    protected static ?string $navigationGroup = 'Довідник';
    protected static ?int $navigationSort = 3;
    protected static ?string $navigationLabel = 'Циклічне меню';
    protected static ?string $pluralModelLabel = 'Циклічне меню';
    protected static ?string $modelLabel = 'День меню';

    /**
     * Обмеження доступу: тільки для Адміністраторів та Менеджерів.
     */
    public static function canViewAny(): bool
    {
        return auth()->user()->role === 'admin' || auth()->user()->role === 'manager';
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Параметри дня')
                    ->description('Вкажіть номер дня в циклі. Система автоматично повторюватиме цей раціон згідно з налаштуваннями бізнесу.')
                    ->schema([
                        TextInput::make('day_number')
                            ->label('Номер дня в циклі')
                            ->placeholder('Наприклад: 1')
                            ->numeric()
                            ->required()
                            ->minValue(1)
                            ->maxValue(fn () => (int) Setting::where('key', 'menu_cycle_days')->value('value') ?: 24)
                            ->unique(ignoreRecord: true) 
                            ->validationMessages([
                                'max' => 'Ви не можете створити день №:value, оскільки тривалість циклу в налаштуваннях обмежена :max днями.',
                                'unique' => 'Меню для цього дня вже існує.',
                            ])
                            ->helperText(fn () => 'Максимально допустимий день зараз: ' . (Setting::where('key', 'menu_cycle_days')->value('value') ?: 24)),
                    ]),
                
                Forms\Components\Section::make('Склад раціону')
                    ->schema([
                        Repeater::make('menuItems')
                            ->relationship()
                            ->label('Страви на цей день')
                            ->schema([
                                Select::make('meal_type_id')
                                    ->relationship('mealType', 'name')
                                    ->required()
                                    ->label('Прийом їжі'),
                                    
                                Select::make('dish_id')
                                    ->relationship('dish', 'name')
                                    ->searchable()
                                    ->preload()
                                    ->required()
                                    ->label('Страва'),
                            ])
                            ->columns(2)
                            ->itemLabel(fn (array $state): ?string => 
                                $state['dish_id'] ? \App\Models\Dish::find($state['dish_id'])?->name : null
                            )
                            ->collapsible() 
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['menuItems.dish', 'menuItems.mealType'])) // Оптимізація запитів до БД
            ->columns([
                Tables\Columns\TextColumn::make('day_number')
                    ->label('День')
                    ->badge()
                    ->color('info')
                    ->sortable()
                    ->alignCenter(),

                // 🔥 ВАРТІСТЬ: 950 ккал (Сніданок - 1, Обід - 3, Вечеря - 5)
                Tables\Columns\TextColumn::make('cost_950')
                    ->label('950 ккал (3 стр)')
                    ->getStateUsing(fn (DailyMenu $record) => self::calculatePlanCost($record, 950, [1, 3, 5]))
                    ->money('UAH')
                    ->color('success')
                    ->weight('bold'),

                // 🔥 ВАРТІСТЬ: 1200 ккал (Без перекусу 1 - залишаємо 1, 3, 4, 5)
                Tables\Columns\TextColumn::make('cost_1200')
                    ->label('1200 ккал (4 стр)')
                    ->getStateUsing(fn (DailyMenu $record) => self::calculatePlanCost($record, 1200, [1, 3, 4, 5]))
                    ->money('UAH')
                    ->color('success')
                    ->weight('bold'),

                // 🔥 ВАРТІСТЬ: 1800 ккал (Всі 5 страв)
                Tables\Columns\TextColumn::make('cost_1800')
                    ->label('1800 ккал (5 стр)')
                    ->getStateUsing(fn (DailyMenu $record) => self::calculatePlanCost($record, 1800, [1, 2, 3, 4, 5]))
                    ->money('UAH')
                    ->color('warning')
                    ->weight('bold'),

                // 🔥 ВАРТІСТЬ: 2100 ккал (Всі 5 страв)
                Tables\Columns\TextColumn::make('cost_2100')
                    ->label('2100 ккал')
                    ->getStateUsing(fn (DailyMenu $record) => self::calculatePlanCost($record, 2100, [1, 2, 3, 4, 5]))
                    ->money('UAH')
                    ->color('danger')
                    ->weight('bold'),

                // 🔥 ВАРТІСТЬ: 2500 ккал (Всі 5 страв)
                Tables\Columns\TextColumn::make('cost_2500')
                    ->label('2500 ккал')
                    ->getStateUsing(fn (DailyMenu $record) => self::calculatePlanCost($record, 2500, [1, 2, 3, 4, 5]))
                    ->money('UAH')
                    ->color('danger')
                    ->weight('bold'),
            ])
            ->defaultSort('day_number', 'asc') 
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListDailyMenus::route('/'),
            'create' => Pages\CreateDailyMenu::route('/create'),
            'edit' => Pages\EditDailyMenu::route('/{record}/edit'),
        ];
    }

    /**
     * 🔥 АЛГОРИТМ РОЗРАХУНКУ СОБІВАРТОСТІ ДНЯ ДЛЯ ПЕВНОЇ КАЛОРІЙНОСТІ
     */
    public static function calculatePlanCost(DailyMenu $record, int $targetKcal, array $allowedSortOrders): float
    {
        // 1. Відбираємо тільки дозволені прийоми їжі для цієї калорійності
        $menuItems = $record->menuItems->filter(function ($item) use ($allowedSortOrders) {
            return $item->dish && in_array($item->mealType?->sort_order, $allowedSortOrders);
        });

        if ($menuItems->isEmpty()) {
            return 0.0;
        }

        // 2. Рахуємо суму відсотків енергії тих страв, які залишились
        $percentSum = $menuItems->sum(fn ($item) => (float)($item->mealType?->energy_percent ?? 0));
        if ($percentSum <= 0) {
            $percentSum = 100.0;
        }

        $totalCost = 0.0;

        foreach ($menuItems as $item) {
            $dish = $item->dish;
            $p = (float)($item->mealType?->energy_percent ?? 0);

            // Кількість калорій на цей конкретний прийом їжі
            $mealKcal = ($p > 0)
                ? $targetKcal * ($p / $percentSum)
                : $targetKcal * (1.0 / $menuItems->count());

            // Кількість калорій у 100г цієї страви
            $baseW = (float)($dish->base_weight_g ?? 0);
            $dishTotalKcal = (float)($dish->total_kcal ?? 0);
            $kcalPer100 = ($baseW > 0 && $dishTotalKcal > 0) ? ($dishTotalKcal / $baseW) * 100.0 : 0;

            // Скільки грамів цієї страви потрібно покласти
            $weightGrams = ($kcalPer100 > 0) ? ($mealKcal / $kcalPer100) * 100.0 : 0;

            // Собівартість 1 граму цієї страви
            $outW = (float)($dish->output_weight ?? $baseW);
            $recipeCost = (float)($dish->total_cost ?? 0);
            $costPerGram = ($outW > 0) ? ($recipeCost / $outW) : 0;

            // Додаємо вартість цієї порції до загальної суми дня
            $totalCost += ($weightGrams * $costPerGram);
        }

        return round($totalCost, 2);
    }
}