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

                        TextInput::make('target_kcal')
                            ->label('Цільова калорійність')
                            ->numeric()
                            ->default(1500)
                            ->suffix('ккал')
                            ->minValue(500)
                            ->maxValue(5000)
                            ->live()
                            ->helperText('Всі розрахунки нутрієнтів та вага порцій перераховуються автоматично'),
                    ])
                    ->columns(2),
                
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
                                    ->content(function (Forms\Get $get, $livewire) {
                                        $dishId        = $get('dish_id');
                                        $mealTypeId    = $get('meal_type_id');
                                        $customPercent = $get('custom_energy_percent');

                                        if (!$dishId || !$mealTypeId) {
                                            return new HtmlString('');
                                        }

                                        $dish     = \App\Models\Dish::find($dishId);
                                        $mealType = \App\Models\MealType::find($mealTypeId);

                                        if (!$dish || !$mealType) {
                                            return new HtmlString('');
                                        }

                                        // Read directly from $livewire->data — always the current form state,
                                        // works reliably inside repeater items
                                        $formData   = $livewire->data ?? [];
                                        $targetKcal = max(500, (int)(($formData['target_kcal'] ?? null) ?: 1500));

                                        $p = ($customPercent !== null && $customPercent !== '')
                                            ? (float)$customPercent
                                            : (float)($mealType->energy_percent ?? 0);

                                        $mealKcal    = $targetKcal * ($p / 100.0);
                                        $baseW       = (float)($dish->base_weight_g ?? 0);
                                        $totalKcal   = (float)($dish->total_kcal ?? 0);
                                        $kcalPer100  = ($baseW > 0 && $totalKcal > 0) ? ($totalKcal / $baseW) * 100.0 : 0;
                                        $weightGrams = ($kcalPer100 > 0) ? ($mealKcal / $kcalPer100) * 100.0 : 0;

                                        $outW        = (float)($dish->output_weight ?? $baseW);
                                        $recipeCost  = (float)($dish->total_cost ?? 0);
                                        $costPerGram = ($outW > 0) ? ($recipeCost / $outW) : 0;
                                        $cost        = $weightGrams * $costPerGram;
                                        $costColor   = $cost > 50 ? '#ef4444' : ($cost > 30 ? '#f59e0b' : '#22c55e');

                                        $dt    = $dish->calculated_totals;
                                        $scale = ($baseW > 0) ? ($weightGrams / $baseW) : 0;
                                        $prot  = round((float)($dt['prot'] ?? 0) * $scale, 1);
                                        $fat   = round((float)($dt['fat']  ?? 0) * $scale, 1);
                                        $carb  = round((float)($dt['carb'] ?? 0) * $scale, 1);
                                        $kcal  = round($mealKcal);

                                        // Collect all day items for contextual hints (Block 3)
                                        $rawItems        = $formData['menuItems'] ?? [];
                                        $allItems        = is_array($rawItems) ? array_values($rawItems) : [];
                                        $dayContext       = self::calculateDayContext($allItems, $targetKcal);
                                        $dishOccurrences = count(array_filter(
                                            $allItems,
                                            fn($item) => isset($item['dish_id']) && $item['dish_id'] == $dishId
                                        ));
                                        $hints       = self::buildDishHints(
                                            $kcal, $prot, $fat, $carb,
                                            $weightGrams,
                                            $mealType->name ?? '',
                                            (int)($mealType->sort_order ?? 0),
                                            $targetKcal,
                                            $dishOccurrences,
                                            $dayContext
                                        );
                                        $hintIconHtml = self::renderHintTooltip($hints);

                                        $cell = fn($label, $val, $valColor = '#e5e7eb') =>
                                            "<div style='display:flex;flex-direction:column;align-items:center;padding:8px 18px;text-align:center;'>" .
                                            "<span style='font-size:10px;font-weight:500;color:#6b7280;text-transform:uppercase;letter-spacing:0.6px;margin-bottom:3px;'>{$label}</span>" .
                                            "<span style='font-size:17px;font-weight:800;color:{$valColor};line-height:1;'>{$val}</span>" .
                                            "</div>";

                                        $sep = "<div style='width:1px;background:#374151;align-self:stretch;margin:6px 0;'></div>";

                                        return new HtmlString(
                                            "<div style='display:flex;align-items:center;gap:10px;'>" .
                                            "<div style='flex:1;display:flex;align-items:stretch;border:1px solid #374151;border-radius:10px;overflow:hidden;'>" .
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
                                            "<span style='font-size:11px;color:#4b5563;'>{$p}% · на {$targetKcal} ккал</span>" .
                                            "</div>" .
                                            "</div>" .
                                            $hintIconHtml .
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
            ->paginated(false)
            ->columns([
                Tables\Columns\TextColumn::make('day_number')
                    ->label('День')
                    ->badge()
                    ->color('info')
                    ->sortable()
                    ->alignCenter(),

                self::makeCachedCostColumn('cached_cost_950',  '950 ккал',  'success', true),
                self::makeCachedCostColumn('cached_cost_1500', '1500 ккал', 'warning', false),
                self::makeCachedCostColumn('cached_cost_2500', '2500 ккал', 'danger',  false),
            ])
            ->defaultSort('day_number', 'asc')
            ->headerActions([
                Tables\Actions\Action::make('recalculate_all')
                    ->label('Перерахувати всі')
                    ->icon('heroicon-o-arrow-path')
                    ->color('gray')
                    ->tooltip('Оновлює собівартість після зміни цін на інгредієнти')
                    ->action(function () {
                        $records = DailyMenu::with([
                            'menuItems.dish.dishIngredients.ingredient',
                            'menuItems.dish.dishIngredients.childDish.dishIngredients.ingredient',
                            'menuItems.mealType',
                        ])->get();

                        foreach ($records as $record) {
                            $record->updateQuietly([
                                'cached_cost_950'  => self::calculatePlanCost($record, 950,  [1, 3, 5]),
                                'cached_cost_1500' => self::calculatePlanCost($record, 1500, [1, 2, 3, 4, 5]),
                                'cached_cost_2500' => self::calculatePlanCost($record, 2500, [1, 2, 3, 4, 5]),
                            ]);
                        }
                    })
                    ->requiresConfirmation()
                    ->modalHeading('Перерахувати собівартість всіх днів?')
                    ->modalDescription('Потрібно робити після зміни цін на інгредієнти. Може зайняти кілька секунд.')
                    ->modalSubmitActionLabel('Перерахувати'),
            ])
            ->actions([
                Tables\Actions\EditAction::make()->iconButton(),
                Tables\Actions\DeleteAction::make()->iconButton(),
            ]);
    }

    private static function makeCachedCostColumn(string $column, string $label, string $color, bool $showSummaryLabel)
    {
        return Tables\Columns\TextColumn::make($column)
            ->label($label)
            ->money('UAH')
            ->color(fn (DailyMenu $record) => $record->{$column} === null ? 'gray' : $color)
            ->weight('bold')
            ->alignRight()
            ->placeholder('—')
            ->summarize(
                Tables\Columns\Summarizers\Average::make()
                    ->label($showSummaryLabel ? 'Середня' : '')
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

    // ─── Hint helpers ─────────────────────────────────────────────────────────

    private static function calculateDayContext(array $allItems, int $targetKcal): array
    {
        $dayProt = $dayFat = $dayCarb = $dayKcal = $dayCost = $totalPercent = 0.0;
        $count = 0;

        foreach ($allItems as $item) {
            $dId  = $item['dish_id']              ?? null;
            $mtId = $item['meal_type_id']          ?? null;
            $cp   = $item['custom_energy_percent'] ?? null;

            if (!$dId || !$mtId) continue;

            $dish     = \App\Models\Dish::find($dId);
            $mealType = \App\Models\MealType::find($mtId);
            if (!$dish || !$mealType) continue;

            $p = ($cp !== null && $cp !== '') ? (float)$cp : (float)($mealType->energy_percent ?? 0);
            $totalPercent += $p;

            $mealKcal   = $targetKcal * ($p / 100.0);
            $baseW      = (float)($dish->base_weight_g ?? 0);
            $dishKcal   = (float)($dish->total_kcal ?? 0);
            $kcalPer100 = ($baseW > 0 && $dishKcal > 0) ? ($dishKcal / $baseW) * 100.0 : 0;
            $weightG    = ($kcalPer100 > 0) ? ($mealKcal / $kcalPer100) * 100.0 : 0;

            $scale   = ($baseW > 0) ? ($weightG / $baseW) : 0;
            $dt      = $dish->calculated_totals;

            $dayProt += (float)($dt['prot'] ?? 0) * $scale;
            $dayFat  += (float)($dt['fat']  ?? 0) * $scale;
            $dayCarb += (float)($dt['carb'] ?? 0) * $scale;
            $dayKcal += $mealKcal;

            $outW        = (float)($dish->output_weight ?? $baseW);
            $costPerGram = ($outW > 0) ? ((float)($dish->total_cost ?? 0) / $outW) : 0;
            $dayCost    += $weightG * $costPerGram;

            $count++;
        }

        if ($count === 0) return [];

        return [
            'prot'         => round($dayProt, 1),
            'fat'          => round($dayFat, 1),
            'carb'         => round($dayCarb, 1),
            'kcal'         => round($dayKcal),
            'cost'         => round($dayCost, 2),
            'totalPercent' => round($totalPercent, 1),
        ];
    }

    private static function buildDishHints(
        float $kcal, float $prot, float $fat, float $carb,
        float $weightGrams,
        string $mealTypeName, int $mealTypeSortOrder,
        int $targetKcal,
        int $dishOccurrences,
        array $dayContext
    ): array {
        $warnings = $infos = $suggestions = [];

        // Pre-calculate macro kcal (used throughout)
        $protKcal       = $prot * 4;
        $fatKcal        = $fat  * 9;
        $carbKcal       = $carb * 4;
        $totalMacroKcal = $protKcal + $fatKcal + $carbKcal;

        // Is this an evening meal? Check by name — works for both 5-dish and 6-dish plans
        $isEvening = mb_stripos($mealTypeName, 'вечер') !== false;

        // ── Block 1: Warnings (Red) ──────────────────────────────────────────

        // Dish used more than once in the same day
        if ($dishOccurrences > 1) {
            $warnings[] = "Ця страва вже є в цьому дні ({$dishOccurrences} рази). Рекомендується урізноманітнити меню.";
        }

        // Empty calories: high kcal, very low protein AND low carbs
        if ($kcal > 400 && $prot < 10 && $carb < 20) {
            $warnings[] = "«Порожні калорії»: висока калорійність ({$kcal} ккал) при низькому вмісті білка ({$prot}г) та клітковини. Страва не дасть тривалого насичення.";
        }

        // BJU critical imbalance — track to avoid duplicate warning below
        $bjuWarningFired = false;
        if ($totalMacroKcal > 10) {
            $fatPctW  = ($fatKcal  / $totalMacroKcal) * 100;
            $carbPctW = ($carbKcal / $totalMacroKcal) * 100;

            if ($fatPctW > 60) {
                $warnings[] = "Критичний дисбаланс БЖУ: " . round($fatPctW) . "% енергії припадає на Жири. Бажано додати більше білкової складової.";
                $bjuWarningFired = true;
            } elseif ($carbPctW > 65) {
                $warnings[] = "Критичний дисбаланс БЖУ: " . round($carbPctW) . "% енергії припадає на Вуглеводи. Рекомендується додати білок.";
                $bjuWarningFired = true;
            }
        }

        // Fat >> protein — only if BJU warning didn't already fire (avoid duplicate)
        if (!$bjuWarningFired && $prot > 0 && $fat > $prot * 2.5) {
            $warnings[] = "Ризик: жири ({$fat}г) значно перевищують білки ({$prot}г). Для корисного раціону бажано знизити жирність інгредієнтів.";
        }

        // ── Block 2: Informational (Blue) — always shows ─────────────────────

        // Always: macro profile as % of dish kcal
        if ($totalMacroKcal > 0) {
            $protPct = round(($protKcal / $totalMacroKcal) * 100);
            $fatPct  = round(($fatKcal  / $totalMacroKcal) * 100);
            $carbPct = round(($carbKcal / $totalMacroKcal) * 100);
            $infos[] = "Макропрофіль: Б {$protPct}% · Ж {$fatPct}% · В {$carbPct}% від ккал страви (норма: Б 15–35% · Ж 20–35% · В 45–65%)";
        }

        // Always: calorie density
        if ($weightGrams > 0 && $kcal > 0) {
            $kcalPer100g = round($kcal / ($weightGrams / 100));
            if ($kcalPer100g < 100) {
                $density = "дуже низька ({$kcalPer100g} ккал/100г) — підходить для великих порцій";
            } elseif ($kcalPer100g < 200) {
                $density = "низька ({$kcalPer100g} ккал/100г) — легка страва";
            } elseif ($kcalPer100g < 300) {
                $density = "помірна ({$kcalPer100g} ккал/100г)";
            } else {
                $density = "висока ({$kcalPer100g} ккал/100г) — порція буде невеликою";
            }
            $infos[] = "Калорійна щільність: {$density}";
        }

        // Conditional: light but high-protein — ideal for dinner
        if ($kcal < 200 && $prot > 15) {
            $infos[] = "Ідеально для вечері: низькокалорійна ({$kcal} ккал), але ситна завдяки {$prot}г білка.";
        }

        // Conditional: energy bomb — good for lunch or pre-workout
        if ($kcal > 500 && $carb > 50) {
            $infos[] = "Джерело швидкої енергії ({$kcal} ккал, {$carb}г вуглеводів). Найкраще для обіду або перед фізичними навантаженнями.";
        }

        // Conditional: high protein
        if ($prot > 25) {
            $infos[] = "Потужне джерело білка ({$prot}г) — сприяє м'язовому росту та довготривалому насиченню.";
        }

        // ── Block 3: Contextual hints (Orange) ───────────────────────────────

        if (!empty($dayContext)) {
            $dayCarb      = (float)($dayContext['carb']         ?? 0);
            $dayProt      = (float)($dayContext['prot']         ?? 0);
            $dayCost      = (float)($dayContext['cost']         ?? 0);
            $totalPercent = (float)($dayContext['totalPercent'] ?? 0);

            // Day already has too many carbs
            if ($dayCarb > 150 && $carb > 25) {
                $suggestions[] = "День вже містить {$dayCarb}г вуглеводів — це багато. Краще замінити страву на варіант з акцентом на білок або овочі.";
            }

            // Daily protein too low (min ~15% of target kcal)
            $protMin = round($targetKcal * 0.15 / 4);
            if ($dayProt < $protMin) {
                $suggestions[] = "За день лише {$dayProt}г білка — замало. Для {$targetKcal} ккал рекомендується мінімум {$protMin}г. Додайте страви з вищим вмістом білка.";
            }

            // High-fat dish in evening meal — detected by name, not sort_order
            if ($isEvening && $fat > 20) {
                $suggestions[] = "Жирні страви краще засвоюються в першій половині дня. Для вечері рекомендується щось легше.";
            }

            // Day cost is already high
            if ($dayCost > 200) {
                $suggestions[] = "Собівартість дня вже " . number_format($dayCost, 2) . " ₴ — забагато. Рекомендується замінити страву для збереження маржинальності.";
            }

            // Total percent warnings — only fire when truly over budget
            if ($totalPercent > 105) {
                $over = round($totalPercent - 100, 1);
                $warnings[] = "Сума % калорій на день {$totalPercent}% — перевищує норму на {$over}%! Потрібно зменшити порції або прибрати страву.";
            }
        }

        return compact('warnings', 'infos', 'suggestions');
    }

    private static function renderHintTooltip(array $hints): string
    {
        $warnings    = $hints['warnings']    ?? [];
        $infos       = $hints['infos']       ?? [];
        $suggestions = $hints['suggestions'] ?? [];
        // Green = no problems (infos always present but that's fine)
        if (empty($warnings) && empty($suggestions)) {
            $iconColor = '#22c55e';
            $iconChar  = '✓';
        } elseif (!empty($warnings)) {
            $iconColor = '#ef4444';
            $iconChar  = 'i';
        } elseif (!empty($suggestions)) {
            $iconColor = '#f59e0b';
            $iconChar  = 'i';
        } else {
            $iconColor = '#60a5fa';
            $iconChar  = 'i';
        }

        $sections = '';

        if (!empty($warnings)) {
            $sections .= "<div style='margin-bottom:10px;'>"
                . "<div style='font-size:10px;font-weight:700;color:#ef4444;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:5px;'>🚨 Попередження</div>";
            foreach ($warnings as $w) {
                $sections .= "<div style='font-size:11.5px;color:#fca5a5;line-height:1.5;padding:5px 8px;background:rgba(239,68,68,0.1);border-left:2px solid #ef4444;border-radius:3px;margin-bottom:3px;'>"
                    . htmlspecialchars($w) . "</div>";
            }
            $sections .= "</div>";
        }

        if (!empty($infos)) {
            $sections .= "<div style='margin-bottom:10px;'>"
                . "<div style='font-size:10px;font-weight:700;color:#60a5fa;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:5px;'>ℹ️ Інформація</div>";
            foreach ($infos as $info) {
                $sections .= "<div style='font-size:11.5px;color:#93c5fd;line-height:1.5;padding:5px 8px;background:rgba(96,165,250,0.1);border-left:2px solid #60a5fa;border-radius:3px;margin-bottom:3px;'>"
                    . htmlspecialchars($info) . "</div>";
            }
            $sections .= "</div>";
        }

        if (!empty($suggestions)) {
            $sections .= "<div>"
                . "<div style='font-size:10px;font-weight:700;color:#f59e0b;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:5px;'>Контекст дня</div>";
            foreach ($suggestions as $s) {
                $sections .= "<div style='font-size:11.5px;color:#fcd34d;line-height:1.5;padding:5px 8px;background:rgba(245,158,11,0.1);border-left:2px solid #f59e0b;border-radius:3px;margin-bottom:3px;'>"
                    . htmlspecialchars($s) . "</div>";
            }
            $sections .= "</div>";
        }

        if (!empty($warnings) || !empty($infos) || !empty($suggestions)) {
            // sections already built above
        } elseif (empty($sections)) {
            $sections = "<div style='font-size:12px;color:#86efac;'>Страва збалансована.</div>";
        }

        $iconStyle = "width:22px;height:22px;border-radius:50%;border:1.5px solid {$iconColor};color:{$iconColor};"
            . "background:transparent;cursor:pointer;display:flex;align-items:center;justify-content:center;"
            . "font-size:12px;font-weight:700;font-style:italic;font-family:serif;padding:0;line-height:1;flex-shrink:0;";

        return
            "<div x-data=\"{ show: false }\" @click.outside=\"show = false\" style=\"position:relative;flex-shrink:0;align-self:center;\">" .
            "<button type=\"button\" @mouseenter=\"show = true\" @mouseleave=\"show = false\" @click=\"show = !show\" style=\"{$iconStyle}\">{$iconChar}</button>" .
            "<div x-show=\"show\" style=\"position:absolute;bottom:calc(100% + 8px);right:0;z-index:9999;width:360px;max-height:380px;overflow-y:auto;" .
            "background:#111827;border:1px solid #374151;border-radius:10px;padding:14px;box-shadow:0 20px 40px rgba(0,0,0,0.7);\">" .
            $sections .
            "</div></div>";
    }

    // ─── Plan cost ────────────────────────────────────────────────────────────

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