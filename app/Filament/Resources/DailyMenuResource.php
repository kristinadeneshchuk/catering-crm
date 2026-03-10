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
use Filament\Forms\Components\Placeholder;
use Illuminate\Support\HtmlString;

class DailyMenuResource extends Resource
{
    protected static ?string $model = DailyMenu::class;
    protected static ?string $navigationGroup = 'Довідник';
    protected static ?int $navigationSort = 3;
    protected static ?string $navigationLabel = 'Циклічне меню';
    protected static ?string $pluralModelLabel = 'Циклічне меню';
    protected static ?string $modelLabel = 'День меню';

    public static function canViewAny(): bool
    {
        return auth()->user()->role === 'admin' || auth()->user()->role === 'manager';
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Параметри дня')
                    ->description('Вкажіть номер дня в циклі.')
                    ->schema([
                        TextInput::make('day_number')
                            ->label('Номер дня в циклі')
                            ->numeric()
                            ->required()
                            ->minValue(1)
                            ->maxValue(fn () => (int) Setting::where('key', 'menu_cycle_days')->value('value') ?: 24)
                            ->unique(ignoreRecord: true),
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
                                    ->live() 
                                    ->label('Прийом їжі'),
                                
                                // 🔥 ДОДАНО: Поле для кастомного відсотка
                                TextInput::make('custom_energy_percent')
                                    ->label('% ккал (кастом)')
                                    ->numeric()
                                    ->suffix('%')
                                    ->helperText('Порожньо = дефолт')
                                    ->live() // live() обов'язковий, щоб прев'ю ваги/ціни оновлювалося одразу!
                                    ->minValue(1)
                                    ->maxValue(100),
                                    
                                Select::make('dish_id')
                                    ->relationship('dish', 'name')
                                    ->searchable()
                                    ->preload()
                                    ->required()
                                    ->live() 
                                    ->label('Страва'),

                                Placeholder::make('cost_preview')
                                    ->label('Собівартість (на 1500 ккал)')
                                    ->content(function (Forms\Get $get) {
                                        $dishId = $get('dish_id');
                                        $mealTypeId = $get('meal_type_id');
                                        // 🔥 ДОДАНО: Отримуємо значення кастомного поля
                                        $customPercent = $get('custom_energy_percent'); 

                                        if (!$dishId || !$mealTypeId) {
                                            return new HtmlString('<span class="text-gray-400">—</span>');
                                        }

                                        $dish = \App\Models\Dish::find($dishId);
                                        $mealType = \App\Models\MealType::find($mealTypeId);

                                        if (!$dish || !$mealType) {
                                            return new HtmlString('<span class="text-gray-400">—</span>');
                                        }

                                        // Рахуємо конкретно для 1500 ккал
                                        $targetKcal = 1500;
                                        
                                        // 🔥 ЗМІНЕНО: Беремо кастомний відсоток, якщо він є, інакше дефолтний
                                        $p = ($customPercent !== null && $customPercent !== '') 
                                            ? (float)$customPercent 
                                            : (float)($mealType->energy_percent ?? 0);
                                        
                                        $mealKcal = $targetKcal * ($p / 100.0);

                                        $baseW = (float)($dish->base_weight_g ?? 0);
                                        $dishTotalKcal = (float)($dish->total_kcal ?? 0);
                                        $kcalPer100 = ($baseW > 0 && $dishTotalKcal > 0) ? ($dishTotalKcal / $baseW) * 100.0 : 0;

                                        $weightGrams = ($kcalPer100 > 0) ? ($mealKcal / $kcalPer100) * 100.0 : 0;

                                        $outW = (float)($dish->output_weight ?? $baseW);
                                        $recipeCost = (float)($dish->total_cost ?? 0);
                                        $costPerGram = ($outW > 0) ? ($recipeCost / $outW) : 0;

                                        $cost = $weightGrams * $costPerGram;

                                        if ($cost > 50) {
                                            $color = '#ef4444'; // червоний
                                        } elseif ($cost > 30) {
                                            $color = '#f59e0b'; // жовтий
                                        } else {
                                            $color = '#22c55e'; // зелений
                                        }

                                        return new HtmlString(
                                            "<div style='display: flex; flex-direction: column; gap: 2px;'>" .
                                            "<span style='font-size: 16px; font-weight: 800; color: {$color};'>" . number_format($cost, 2) . " ₴</span>" .
                                            "<span style='font-size: 11px; font-weight: 600; color: #6b7280;'>Вага: " . round($weightGrams) . " г (" . round($p) ."%)</span>" .
                                            "</div>"
                                        );
                                    }),
                            ])
                            ->columns(4) // 🔥 ЗМІНЕНО: 4 колонки, щоб влізло нове поле
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
            ->modifyQueryUsing(fn ($query) => $query->with([
                'menuItems.dish.dishIngredients.ingredient',
                'menuItems.dish.dishIngredients.childDish.dishIngredients.ingredient',
                'menuItems.mealType'
            ]))
            ->paginated(false)
            ->columns([
                Tables\Columns\TextColumn::make('day_number')
                    ->label('День')
                    ->badge()
                    ->color('info')
                    ->sortable()
                    ->alignCenter(),

                self::makeCostColumn('cost_950', '950 ккал', 950, [1, 3, 5], 'success', true),
                self::makeCostColumn('cost_1500', '1500 ккал', 1500, [1, 2, 3, 4, 5], 'warning', false),
                self::makeCostColumn('cost_2500', '2500 ккал', 2500, [1, 2, 3, 4, 5], 'danger', false),
            ])
            ->defaultSort('day_number', 'asc') 
            ->actions([
                Tables\Actions\EditAction::make()->iconButton(),
                Tables\Actions\DeleteAction::make()->iconButton(),
            ]);
    }

    private static function makeCostColumn(string $name, string $label, int $kcal, array $meals, string $color, bool $showSummaryLabel)
    {
        return Tables\Columns\TextColumn::make($name)
            ->label($label)
            ->tooltip(count($meals) . ' страв')
            ->getStateUsing(fn (DailyMenu $record) => self::calculatePlanCost($record, $kcal, $meals))
            ->money('UAH')
            ->color($color)
            ->weight('bold')
            ->alignRight()
            ->summarize(
                Tables\Columns\Summarizers\Summarizer::make()
                    ->label($showSummaryLabel ? 'Середня' : '') 
                    ->using(function ($query) use ($kcal, $meals) {
                        $ids = $query->pluck('id');
                        if ($ids->isEmpty()) return 0;
                        
                        $records = DailyMenu::with([
                            'menuItems.dish.dishIngredients.ingredient',
                            'menuItems.dish.dishIngredients.childDish.dishIngredients.ingredient',
                            'menuItems.mealType'
                        ])->whereIn('id', $ids)->get();
                        
                        $sum = $records->sum(fn ($record) => self::calculatePlanCost($record, $kcal, $meals));
                        return $sum / $records->count();
                    })
                    ->money('UAH')
            );
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListDailyMenus::route('/'),
            'create' => Pages\CreateDailyMenu::route('/create'),
            'edit' => Pages\EditDailyMenu::route('/{record}/edit'),
        ];
    }

    public static function calculatePlanCost(DailyMenu $record, int $targetKcal, array $allowedSortOrders): float
    {
        $menuItems = $record->menuItems->filter(function ($item) use ($allowedSortOrders) {
            return $item->dish && in_array($item->mealType?->sort_order, $allowedSortOrders);
        });

        if ($menuItems->isEmpty()) {
            return 0.0;
        }

        // 🔥 ЗМІНЕНО: Сумуємо відсотки з урахуванням кастомних
        $percentSum = $menuItems->sum(function ($item) {
            return $item->custom_energy_percent !== null 
                ? (float) $item->custom_energy_percent 
                : (float) ($item->mealType?->energy_percent ?? 0);
        });

        if ($percentSum <= 0) {
            $percentSum = 100.0;
        }

        $totalCost = 0.0;

        foreach ($menuItems as $item) {
            $dish = $item->dish;
            
            // 🔥 ЗМІНЕНО: Беремо кастомний відсоток, якщо він є
            $p = $item->custom_energy_percent !== null 
                ? (float) $item->custom_energy_percent 
                : (float) ($item->mealType?->energy_percent ?? 0);

            $mealKcal = ($p > 0)
                ? $targetKcal * ($p / $percentSum)
                : $targetKcal * (1.0 / $menuItems->count());

            $baseW = (float)($dish->base_weight_g ?? 0);
            $dishTotalKcal = (float)($dish->total_kcal ?? 0);
            $kcalPer100 = ($baseW > 0 && $dishTotalKcal > 0) ? ($dishTotalKcal / $baseW) * 100.0 : 0;

            $weightGrams = ($kcalPer100 > 0) ? ($mealKcal / $kcalPer100) * 100.0 : 0;

            $outW = (float)($dish->output_weight ?? $baseW);
            $recipeCost = (float)($dish->total_cost ?? 0);
            $costPerGram = ($outW > 0) ? ($recipeCost / $outW) : 0;

            $totalCost += ($weightGrams * $costPerGram);
        }

        return round($totalCost, 2);
    }
}