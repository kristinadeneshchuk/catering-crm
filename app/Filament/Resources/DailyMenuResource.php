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
                                    ->label('Прийом їжі')
                                    ->columnSpan(1),

                                TextInput::make('custom_energy_percent')
                                    ->label('% ккал (кастом)')
                                    ->numeric()
                                    ->suffix('%')
                                    ->helperText('Порожньо = дефолт')
                                    ->live()
                                    ->minValue(1)
                                    ->maxValue(100)
                                    ->columnSpan(1),

                                Select::make('dish_id')
                                    ->relationship('dish', 'name')
                                    ->searchable()
                                    ->preload()
                                    ->required()
                                    ->live()
                                    ->label('Страва')
                                    ->columnSpan(2),

                                Placeholder::make('dish_info')
                                    ->label('')
                                    ->columnSpanFull()
                                    ->content(function (Forms\Get $get) {
                                        $dishId       = $get('dish_id');
                                        $mealTypeId   = $get('meal_type_id');
                                        $customPercent = $get('custom_energy_percent');

                                        if (!$dishId || !$mealTypeId) {
                                            return new HtmlString('');
                                        }

                                        $dish     = \App\Models\Dish::find($dishId);
                                        $mealType = \App\Models\MealType::find($mealTypeId);

                                        if (!$dish || !$mealType) {
                                            return new HtmlString('');
                                        }

                                        $targetKcal = 1500;
                                        $p = ($customPercent !== null && $customPercent !== '')
                                            ? (float)$customPercent
                                            : (float)($mealType->energy_percent ?? 0);

                                        $mealKcal   = $targetKcal * ($p / 100.0);
                                        $baseW      = (float)($dish->base_weight_g ?? 0);
                                        $totalKcal  = (float)($dish->total_kcal ?? 0);
                                        $kcalPer100 = ($baseW > 0 && $totalKcal > 0) ? ($totalKcal / $baseW) * 100.0 : 0;
                                        $weightGrams = ($kcalPer100 > 0) ? ($mealKcal / $kcalPer100) * 100.0 : 0;

                                        $outW        = (float)($dish->output_weight ?? $baseW);
                                        $recipeCost  = (float)($dish->total_cost ?? 0);
                                        $costPerGram = ($outW > 0) ? ($recipeCost / $outW) : 0;
                                        $cost        = $weightGrams * $costPerGram;

                                        $costColor = $cost > 50 ? '#ef4444' : ($cost > 30 ? '#f59e0b' : '#22c55e');

                                        $dt    = $dish->calculated_totals;
                                        $scale = ($baseW > 0) ? ($weightGrams / $baseW) : 0;
                                        $prot  = round((float)($dt['prot'] ?? 0) * $scale, 1);
                                        $fat   = round((float)($dt['fat']  ?? 0) * $scale, 1);
                                        $carb  = round((float)($dt['carb'] ?? 0) * $scale, 1);
                                        $kcal  = round($mealKcal);

                                        $cell = fn($label, $val, $valColor = '#e5e7eb') =>
                                            "<div style='display:flex;flex-direction:column;align-items:center;padding:8px 18px;text-align:center;'>" .
                                            "<span style='font-size:10px;font-weight:500;color:#6b7280;text-transform:uppercase;letter-spacing:0.6px;margin-bottom:3px;'>{$label}</span>" .
                                            "<span style='font-size:17px;font-weight:800;color:{$valColor};line-height:1;'>{$val}</span>" .
                                            "</div>";

                                        $sep = "<div style='width:1px;background:#374151;align-self:stretch;margin:6px 0;'></div>";

                                        return new HtmlString(
                                            "<div style='display:flex;align-items:stretch;border:1px solid #374151;border-radius:10px;overflow:hidden;'>" .
                                            $cell('Ккал', $kcal, '#a78bfa') .
                                            $sep .
                                            $cell('Вага', round($weightGrams) . 'г', '#e5e7eb') .
                                            $sep .
                                            $cell('Білки', $prot . 'г', '#60a5fa') .
                                            $cell('Жири', $fat . 'г', '#fbbf24') .
                                            $cell('Вугл.', $carb . 'г', '#34d399') .
                                            $sep .
                                            "<div style='display:flex;flex-direction:column;align-items:center;padding:8px 18px;text-align:center;'>" .
                                            "<span style='font-size:10px;font-weight:500;color:#6b7280;text-transform:uppercase;letter-spacing:0.6px;margin-bottom:3px;'>Собівартість</span>" .
                                            "<span style='font-size:17px;font-weight:800;color:{$costColor};line-height:1;'>" . number_format($cost, 2) . " ₴</span>" .
                                            "</div>" .
                                            "<div style='margin-left:auto;display:flex;align-items:center;padding:0 16px;'>" .
                                            "<span style='font-size:11px;color:#4b5563;'>{$p}% · на 1500 ккал</span>" .
                                            "</div>" .
                                            "</div>"
                                        );
                                    }),
                            ])
                            ->columns(4)
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

        $totalCost = 0.0;

        foreach ($menuItems as $item) {
            $dish = $item->dish;

            $p = $item->custom_energy_percent !== null
                ? (float) $item->custom_energy_percent
                : (float) ($item->mealType?->energy_percent ?? 0);

            $mealKcal = ($p > 0)
                ? $targetKcal * ($p / 100.0)
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