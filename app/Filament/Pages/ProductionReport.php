<?php

namespace App\Filament\Pages;

use Filament\Pages\Page;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use App\Models\DailyMenu;
use App\Models\Order;
use App\Models\Setting;
use App\Models\Ingredient;
use App\Models\Dish; 
use App\Models\OrderReplacement;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Form;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class ProductionReport extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-presentation-chart-line';
    protected static ?string $navigationLabel = 'План виробництва';
    protected static ?string $title = 'План виробництва';
    protected static string $view = 'filament.pages.production-report';

    public ?array $data = [];
    public array $report = [];
    public float $currentDayNumber = 0;
    protected $activeOrders = null;

    public static function canAccess(): bool
    {
        return in_array(auth()->user()->role, ['admin', 'manager', 'cook']);
    }

    public function mount(): void
    {
        $initialDate = request()->query('date', now()->format('Y-m-d'));
        $this->form->fill(['date' => $initialDate]);
        $this->calculate();
    }

    /**
     * Кнопки у верхній панелі сторінки
     */
  protected function getHeaderActions(): array
{
    // 1. Поточна дата з форми (день роботи)
    $dateParam = $this->data['date'] ?? now()->format('Y-m-d');
    
    // 2. 🔥 Цільова дата (день споживання = завтра)
    // Використовуємо $dateParam замість неіснуючої $selectedDate
    $targetDate = \Carbon\Carbon::parse($dateParam)->addDay()->format('Y-m-d');
    
    $settingKey = "stock_debited_{$dateParam}";
    $isAlreadyDebited = \App\Models\Setting::where('key', $settingKey)->where('value', '1')->exists();

    return [
        \Filament\Actions\Action::make('debit_stock')
            ->label($isAlreadyDebited ? "Зміну за {$dateParam} вже закрито" : 'Закрити зміну та списати склад')
            ->icon($isAlreadyDebited ? 'heroicon-o-lock-closed' : 'heroicon-o-archive-box-arrow-down')
            ->color($isAlreadyDebited ? 'warning' : 'danger')
            ->disabled($isAlreadyDebited) 
            ->requiresConfirmation(fn() => !$isAlreadyDebited)
            ->modalHeading('Підтвердити списання залишків?')
            ->modalDescription('Система автоматично відніме вагу БРУТТО всіх інгредієнтів. Цю дію неможливо скасувати.')
            ->action(function () use ($settingKey, $dateParam) {
                $checkAgain = \App\Models\Setting::where('key', $settingKey)->where('value', '1')->exists();

                if ($checkAgain) {
                    \Filament\Notifications\Notification::make()
                        ->title('Операцію скасовано')
                        ->body("Зміну за {$dateParam} вже закрито.")
                        ->warning()
                        ->send();
                    return; 
                }

                $this->processStockDebiting();
                
                \App\Models\Setting::updateOrCreate(
                    ['key' => $settingKey],
                    ['value' => '1']
                );

                \Filament\Notifications\Notification::make()
                    ->title('Успішно')
                    ->body('Склад списано, зміну закрито.')
                    ->success()
                    ->send();

                return redirect(static::getUrl(['date' => $dateParam]));
            }),
    ];
}

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                DatePicker::make('date')
                    ->label('Дата приготування')
                    ->displayFormat('d.m.Y')
                    ->required()
                    ->live()
                    ->afterStateUpdated(function ($state) {
                        $this->calculate();
                        $this->js("window.history.replaceState(null, null, '?date=' + '{$state}')");
                    }),
            ])
            ->statePath('data');
    }

    protected function getViewData(): array
    {
        return [
            'reportData' => $this->report,
            'dayNumber' => $this->currentDayNumber
        ];
    }

    // === ЕКШН: Скинути заміну ===
    public function resetReplacementAction(): Action
    {
        return Action::make('resetReplacement')
            ->label('Скинути')
            ->icon('heroicon-m-x-mark')
            ->color('gray')
            ->size('xs')
            ->requiresConfirmation()
            ->modalHeading('Скасувати заміну?')
            ->modalDescription('Це поверне оригінальний інгредієнт.')
            ->action(function (array $arguments) {
                OrderReplacement::where('order_id', $arguments['order_id'])
                    ->where('dish_id', $arguments['dish_id'])
                    ->where('original_product_id', $arguments['product_id'])
                    ->delete();

                Notification::make()->title('Заміну скасовано')->success()->send();
                $this->calculate();
            });
    }

    // === ЕКШН 1: ЗАМІНА ІНГРЕДІЄНТА ===
    public function replaceIngredientAction(): Action
    {
        return Action::make('replaceIngredient')
            ->label('Зам. інгредієнт')
            ->icon('heroicon-m-beaker')
            ->color('warning')
            ->size('xs')
            ->modalHeading('Заміна інгредієнта')
            ->form(function (array $arguments) {
                $order = Order::find($arguments['order_id']);
                $excludedIds = [];
                if ($order && $order->client) {
                    $excludedIds = $order->client->ingredientExclusions->pluck('id')->toArray();
                }
                $excludedIds[] = $arguments['product_id'];

                return [
                    Select::make('replacement_product_id')
                        ->label('Замінити на')
                        ->options(function () use ($excludedIds) {
                            return Ingredient::whereNotIn('id', $excludedIds)->limit(50)->pluck('name', 'id');
                        })
                        ->searchable()
                        ->getSearchResultsUsing(fn (string $search) => Ingredient::whereNotIn('id', $excludedIds)->where('name', 'like', "%{$search}%")->limit(50)->pluck('name', 'id'))
                        ->required(),
                    Textarea::make('comment')->label('Коментар'),
                ];
            })
            ->action(function (array $data, array $arguments) {
                OrderReplacement::updateOrCreate(
                    [
                        'order_id' => $arguments['order_id'],
                        'dish_id' => $arguments['dish_id'],
                        'original_product_id' => $arguments['product_id'],
                    ],
                    [
                        'replacement_product_id' => $data['replacement_product_id'],
                        'replacement_dish_id' => null, 
                        'comment' => $data['comment'] ?? null,
                    ]
                );
                Notification::make()->title('Збережено')->success()->send();
                $this->calculate();
            });
    }

    // === ЕКШН 2: ЗАМІНА СТРАВИ ===
    public function replaceDishAction(): Action
    {
        return Action::make('replaceDish')
            ->label('Зам. страву')
            ->icon('heroicon-m-arrow-path-rounded-square')
            ->color('danger') 
            ->size('sm')
            ->modalHeading('Заміна цілої страви')
            ->form(function (array $arguments) {
                $currentDish = Dish::find($arguments['dish_id']);
                $order = Order::find($arguments['order_id']);
                
                $excludedDishIds = [];
                if ($order && $order->client) {
                    $excludedDishIds = $order->client->dishExclusions->pluck('id')->toArray();
                }
                $excludedDishIds[] = $currentDish->id; 

                return [
                    Select::make('replacement_dish_id')
                        ->label('Обрати іншу страву')
                        ->options(function () use ($excludedDishIds) {
                            return Dish::whereNotIn('id', $excludedDishIds)->limit(50)->pluck('name', 'id');
                        })
                        ->searchable()
                        ->required(),
                    Textarea::make('comment')->label('Коментар'),
                ];
            })
            ->action(function (array $data, array $arguments) {
                OrderReplacement::updateOrCreate(
                    [
                        'order_id' => $arguments['order_id'],
                        'dish_id' => $arguments['dish_id'],
                        'original_product_id' => null, 
                    ],
                    [
                        'replacement_dish_id' => $data['replacement_dish_id'],
                        'replacement_product_id' => null,
                        'comment' => $data['comment'] ?? null,
                    ]
                );
                Notification::make()->title('Страву замінено')->success()->send();
                $this->calculate();
            });
    }

    /**
     * Розрахунок звіту
     */
public function calculate(): void
    {
        // 1. Отримуємо вибрану дату приготування (наприклад, 11.02)
        $selectedDate = $this->data['date'] ?? now()->format('Y-m-d');
        
        // 2. 🔥 ГОЛОВНА ЛОГІКА: Кухня готує сьогодні на ЗАВТРА
        // Встановлюємо цільову дату споживання (targetDate = 12.02)
        $targetDate = \Carbon\Carbon::parse($selectedDate)->addDay()->format('Y-m-d');
        
        $this->report = [];

        $cycleDays = (int) Setting::where('key', 'menu_cycle_days')->value('value') ?: 24;
        $anchorDate = Carbon::parse('2025-01-01');
        
        // 3. Розраховуємо номер дня меню для дати СПОЖИВАННЯ (завтрашньої)
        $this->currentDayNumber = (abs(Carbon::parse($targetDate)->diffInDays($anchorDate)) % $cycleDays) + 1;

        $menu = DailyMenu::where('day_number', $this->currentDayNumber)
            ->with(['menuItems.dish.dishIngredients.ingredient', 'menuItems.dish.dishIngredients.childDish.dishIngredients.ingredient', 'menuItems.mealType'])
            ->first();

        if ($menu) {
            // 4. Шукаємо активні замовлення, які припадають на дату СПОЖИВАННЯ
            $this->activeOrders = Order::whereDate('start_date', '<=', $targetDate)
                ->whereDate('end_date', '>=', $targetDate)
                ->whereIn('status', ['new', 'active'])
                ->with([
                    'client.mealTypes',
                    'client.ingredientExclusions', 
                    'client.dishExclusions',
                    'replacements.replacementProduct',
                    'replacements.replacementDish.dishIngredients.ingredient',
                    'replacements.replacementDish.dishIngredients.childDish.dishIngredients.ingredient',
                ])
                ->get();

            if ($this->activeOrders->isNotEmpty()) {
                
                $sortedMenuItems = $menu->menuItems->sortBy(fn($item) => $item->mealType->sort_order ?? 99);

                foreach ($sortedMenuItems as $item) {
                    if (!$item->dish) continue;
                    $mealName = $item->mealType->name ?? 'Інше';
                    $dish = $item->dish;

                    $standardOrders = [];
                    $customOrders = [];

                    foreach ($this->activeOrders as $order) {
                        $clientMealTypeIds = $order->client->mealTypes->pluck('id')->toArray();
                        if ($item->meal_type_id && !in_array($item->meal_type_id, $clientMealTypeIds)) {
                            continue;
                        }

                        if (!isset($order->base_scale_factor)) {
                            $order->base_scale_factor = (float)($order->scale_factor ?: 1.0);
                        }
                        
                        $activePercentSum = $order->client->mealTypes->sum('energy_percent');
                        $redistributionFactor = ($activePercentSum > 0 && $activePercentSum < 100) ? (100 / $activePercentSum) : 1.0;
                        $order->scale_factor = $order->base_scale_factor * $redistributionFactor;

                        $isCustom = false;
                        $clientComment = $order->client->production_comment ?? $order->client->comment ?? null;
                        if ($order->comment || !empty($clientComment)) $isCustom = true;
                        if ($order->replacements->where('dish_id', $dish->id)->first()) $isCustom = true;
                        if ($order->client->dishExclusions->contains('id', $dish->id)) $isCustom = true;
                        
                        if (!$isCustom) {
                             $clientExclusions = $order->client->ingredientExclusions;
                             
                             if ($clientExclusions->isNotEmpty()) {
                                 if ($this->checkRecursiveConflict($dish, $clientExclusions)) {
                                     $isCustom = true;
                                 }
                             }
                        }

                        if ($isCustom) {
                            $customOrders[] = $order;
                        } else {
                            $standardOrders[] = $order;
                        }
                    }

                    if (empty($standardOrders) && empty($customOrders)) continue;

                    $standardStructure = $this->calculateIngredientsStructure($dish, $standardOrders);
                    $stdTotals = $this->calculateTotals($standardStructure);

                    $customCards = [];
                    foreach ($customOrders as $order) {
                        $customCards[] = $this->buildCustomCard($dish, $order);
                    }

                    $this->report[$mealName][] = [
                        'meal_name' => $mealName,
                        'dish_id' => $dish->id,
                        'dish_name' => $dish->name,
                        'standard_count' => count($standardOrders),
                        'standard_structure' => $standardStructure,
                        'standard_total_netto' => $stdTotals['netto'],
                        'standard_total_brutto' => $stdTotals['brutto'],
                        'custom_cards' => $customCards, 
                    ];
                }
            }
        }
    }

    /**
     * Основний метод списання складських залишків
     */
    public function processStockDebiting(): void
    {
        $this->calculate();
        $ingredientsToDebit = [];

        foreach ($this->report as $mealDishes) {
            foreach ($mealDishes as $dishData) {
                foreach ($dishData['standard_structure'] as $comp) {
                    $this->collectIngredientsRecursive($comp, $ingredientsToDebit);
                }
                foreach ($dishData['custom_cards'] as $card) {
                    if ($card['dish_excluded'] && !isset($card['dish_replacement'])) {
                        continue;
                    }
                    foreach ($card['components'] as $comp) {
                        $this->collectIngredientsRecursive($comp, $ingredientsToDebit);
                    }
                }
            }
        }

        if (empty($ingredientsToDebit)) {
            Notification::make()->title('Немає даних для списання')->warning()->send();
            return;
        }

        DB::transaction(function () use ($ingredientsToDebit) {
            foreach ($ingredientsToDebit as $id => $totalWeight) {
                Ingredient::where('id', $id)->decrement('stock', $totalWeight);
            }
        });

        Notification::make()
            ->title('Зміну закрито')
            ->body('Залишки на складі успішно оновлено.')
            ->success()
            ->send();
    }

    private function collectIngredientsRecursive(array $component, array &$accumulator): void
    {
        if ($component['type'] === 'product' && isset($component['product_id'])) {
            $id = $component['product_id'];
            $weight = (float)$component['weight_brutto'];
            
            if (!isset($accumulator[$id])) {
                $accumulator[$id] = 0;
            }
            $accumulator[$id] += $weight;
        } 
        elseif ($component['type'] === 'pf' && isset($component['sub_ingredients'])) {
            foreach ($component['sub_ingredients'] as $sub) {
                $this->collectIngredientsRecursive($sub, $accumulator);
            }
        }
    }

    private function calculateIngredientsStructure($dish, $orders): array
    {
        if (empty($orders)) return [];
        $totalScale = collect($orders)->sum(fn($o) => (float)($o->scale_factor ?: 1.0));
        return $this->getHierarchicalIngredients($dish, $totalScale, 1, null, false); 
    }

    private function buildCustomCard($dish, $order)
    {
        $scale = (float)($order->scale_factor ?: 1.0);
        $dishExclusion = $order->client->dishExclusions->contains('id', $dish->id);
        $dishReplacement = $order->replacements->where('dish_id', $dish->id)->whereNull('original_product_id')->first();

        $components = [];
        $replacementDishName = null;
        $replacementDishId = null;

        if ($dishReplacement && $dishReplacement->replacementDish) {
            $replacementDishName = $dishReplacement->replacementDish->name;
            $replacementDishId = $dishReplacement->replacementDish->id;
            $components = $this->getHierarchicalIngredients($dishReplacement->replacementDish, $scale, 1, $dishReplacement->replacementDish->id, true, $order);
        } else {
            $components = $this->getHierarchicalIngredients($dish, $scale, 1, $dish->id, true, $order);
        }

        $totals = $this->calculateTotals($components);
        $clientComment = $order->client->production_comment ?? $order->client->comment ?? null;
        $finalComment = trim(($clientComment ?? '') . ' ' . ($order->comment ?? ''));

        return [
            'client_name' => $order->client->name,
            'order_id' => $order->id,
            'comment' => $finalComment, 
            'dish_excluded' => $dishExclusion,
            'dish_replacement' => $replacementDishName,
            'replacement_dish_id' => $replacementDishId, 
            'components' => $components,
            'total_netto' => $totals['netto'],
            'total_brutto' => $totals['brutto'],
        ];
    }

    private function calculateTotals(array $components): array
    {
        $netto = 0; $brutto = 0;
        foreach ($components as $comp) {
            if ($comp['type'] === 'pf') {
                $netto += $comp['weight_output'] ?? 0;
                $brutto += $comp['weight_brutto_sum'] ?? 0;
            } else {
                $netto += $comp['weight_netto'] ?? 0;
                $brutto += $comp['weight_brutto'] ?? 0;
            }
        }
        return ['netto' => round($netto), 'brutto' => round($brutto)];
    }

    private function getHierarchicalIngredients($dish, $scale, $subRatio = 1, $rootDishId = null, $checkConflicts = true, $specificOrder = null): array
    {
        $components = [];
        if (!$dish || !$dish->dishIngredients) return $components;
        if (!$rootDishId) $rootDishId = $dish->id;

        foreach ($dish->dishIngredients as $di) {
            $currentK = $scale * $subRatio;
            $type = mb_strtolower(trim($di->type));
            $nettoTotalRaw = (float)$di->net_weight_g * $currentK;

            $conflictData = null;
            $replacementInfo = null;

            if ($checkConflicts && $specificOrder && in_array($type, ['product', 'продукт']) && $di->ingredient) {
                $ingId = $di->ingredient->id;
                if ($specificOrder->client->ingredientExclusions->contains('id', $ingId)) {
                    $rep = $specificOrder->replacements->where('dish_id', $rootDishId)->where('original_product_id', $ingId)->first();
                    if ($rep && $rep->replacementProduct) {
                        $newYield = (float)($rep->replacementProduct->yield_percent ?: 100);
                        $replacementInfo = [
                            'name' => $rep->replacementProduct->name,
                            'netto' => round($nettoTotalRaw, 1),
                            'brutto' => round(($nettoTotalRaw * 100) / ($newYield ?: 100), 1),
                            'unit' => $rep->replacementProduct->unit ?? 'г'
                        ];
                    }
                    $conflictData = ['is_resolved' => !!$replacementInfo, 'replacement' => $replacementInfo, 'original_ing_id' => $ingId];
                }
            }

            if (in_array($type, ['product', 'продукт']) && $di->ingredient) {
                $yield = (float)($di->ingredient->yield_percent ?: 100);
                $components[] = [
                    'type' => 'product',
                    'name' => $di->ingredient->name,
                    'weight_netto' => round($nettoTotalRaw, 1),
                    'weight_brutto' => round(($nettoTotalRaw * 100) / ($yield ?: 100), 1),
                    'unit' => $di->ingredient->unit ?? 'г',
                    'conflict' => $conflictData, 
                    'product_id' => $di->ingredient->id,
                ];
            } elseif (in_array($type, ['pf', 'напівфабрикат']) && $di->childDish) {
                $pfBase = (float)$di->childDish->base_weight_g ?: 100;
                $pfRatio = $di->net_weight_g / $pfBase;
                $subIngredients = $this->getHierarchicalIngredients($di->childDish, $scale, $pfRatio * $subRatio, $rootDishId, $checkConflicts, $specificOrder);
                
                $sumNetto = 0; $sumBrutto = 0;
                
                foreach($subIngredients as $s) { 
                    $sumNetto += $s['weight_netto'] ?? $s['weight_output'] ?? 0; 
                    $sumBrutto += $s['weight_brutto'] ?? $s['weight_brutto_sum'] ?? 0; 
                }

                $components[] = [
                    'type' => 'pf',
                    'name' => $di->childDish->name,
                    'weight_output' => round($nettoTotalRaw),
                    'weight_netto_sum' => round($sumNetto),
                    'weight_brutto_sum' => round($sumBrutto),
                    'sub_ingredients' => $subIngredients
                ];
            }
        }
        return $components;
    }

    /**
     * 🔥🔥🔥 НОВА ФУНКЦІЯ: Глибока перевірка конфліктів (рекурсія) 🔥🔥🔥
     * Дозволяє знайти шпинат навіть якщо він захований у "Зеленому маслі"
     */
    private function checkRecursiveConflict($dish, $exclusions): bool
    {
        if (!$dish || !$dish->dishIngredients) return false;

        foreach ($dish->dishIngredients as $di) {
            // 1. Перевіряємо прямий інгредієнт
            if ($di->ingredient_id && $exclusions->contains('id', $di->ingredient_id)) {
                return true;
            }
            
            // 2. Якщо це ПФ — пірнаємо всередину
            if ($di->child_dish_id && $di->childDish) {
                if ($this->checkRecursiveConflict($di->childDish, $exclusions)) {
                    return true;
                }
            }
        }
        return false;
    }
}