<?php

namespace App\Filament\Pages;

use Filament\Pages\Page;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Contracts\HasForms;
use App\Models\DailyMenu;
use App\Models\Order;
use App\Models\Setting;
use App\Models\Ingredient;
use App\Models\Dish;
use App\Models\OrderReplacement;
use App\Models\ReplacementBundle;
use App\Models\DishIngredient;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Form;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\Placeholder;
use Illuminate\Support\HtmlString;

class ProductionReport extends Page implements HasForms
{
    use InteractsWithForms, InteractsWithActions;

    protected static ?string $navigationIcon = 'heroicon-o-presentation-chart-line';
    protected static ?string $navigationLabel = 'План виробництва';
    protected static ?string $title = 'План виробництва';
    protected static string $view = 'filament.pages.production-report';

    public ?array $data = [];
    public array $report = [];
    public float $currentDayNumber = 0;
    protected $activeOrders = null;

    public ?string $debugMessage = null;

    /** @var array<int, array{items: array, totals: array}> */
    private array $orderPlans = [];

    public static function canAccess(): bool
    {
        return in_array(auth()->user()->role, ['admin', 'manager', 'cook'], true);
    }

    public function mount(): void
    {
        $initialDate = request()->query('date', now()->format('Y-m-d'));
        $this->form->fill(['date' => $initialDate]);
        $this->calculate();
    }

    protected function getHeaderActions(): array
    {
        $dateParam = $this->data['date'] ?? now()->format('Y-m-d');

        $settingKey = "stock_debited_{$dateParam}";
        $isAlreadyDebited = Setting::where('key', $settingKey)->where('value', '1')->exists();

        return [
            Action::make('debit_stock')
                ->label($isAlreadyDebited ? "Зміну за {$dateParam} вже закрито" : 'Закрити зміну та списати склад')
                ->icon($isAlreadyDebited ? 'heroicon-o-lock-closed' : 'heroicon-o-archive-box-arrow-down')
                ->color($isAlreadyDebited ? 'warning' : 'danger')
                ->disabled($isAlreadyDebited)
                ->requiresConfirmation(fn () => !$isAlreadyDebited)
                ->modalHeading('Підтвердити списання залишків?')
                ->modalDescription('Система автоматично відніме вагу БРУТТО всіх інгредієнтів. Цю дію неможливо скасувати.')
                ->action(function () use ($settingKey, $dateParam) {
                    $checkAgain = Setting::where('key', $settingKey)->where('value', '1')->exists();

                    if ($checkAgain) {
                        Notification::make()
                            ->title('Операцію скасовано')
                            ->body("Зміну за {$dateParam} вже закрито.")
                            ->warning()
                            ->send();
                        return;
                    }

                    $this->processStockDebiting();

                    Setting::updateOrCreate(
                        ['key' => $settingKey],
                        ['value' => '1']
                    );

                    Notification::make()
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
                Grid::make(2)->schema([
                    DatePicker::make('date')
                        ->label('Дата приготування (сьогодні)')
                        ->displayFormat('d.m.Y')
                        ->required()
                        ->live()
                        ->afterStateUpdated(function ($state) {
                            $this->calculate();
                            $this->js("window.history.replaceState(null, null, '?date=' + '{$state}')");
                        }),

                    \Filament\Forms\Components\Group::make()->schema([
                        Placeholder::make('info_text')
                            ->label('Цільова дата')
                            ->content(function () {
                                $selectedDate = $this->data['date'] ?? now()->format('Y-m-d');
                                $targetDateObj = Carbon::parse($selectedDate)->addDay();

                                $cycleDays = (int) Setting::where('key', 'menu_cycle_days')->value('value') ?: 24;
                                $startDateStr = Setting::where('key', 'menu_cycle_start_date')->value('value') ?: '2025-01-01';
                                $anchorDate = Carbon::parse($startDateStr);

                                $diff = abs($targetDateObj->diffInDays($anchorDate));
                                $dayNum = ($diff % $cycleDays) + 1;

                                return new HtmlString(
                                    "<div class='p-2 bg-primary-500/10 border border-primary-500 rounded-lg text-primary-600'>
                                        👨‍🍳 Кухня готує сьогодні на <strong>завтра (" . $targetDateObj->format('d.m.Y') . ")</strong>.
                                        <br> Це буде <strong>" . $dayNum . "-й день</strong> циклу меню.
                                    </div>"
                                );
                            }),

                        \Filament\Forms\Components\Actions::make([
                            \Filament\Forms\Components\Actions\Action::make('print_view')
                                ->label('Версія для друку')
                                ->color('warning')
                                ->url(fn () => route('print.production-report', ['date' => $this->data['date'] ?? now()->format('Y-m-d')]))
                                ->openUrlInNewTab(),
                            \Filament\Forms\Components\Actions\Action::make('print_stock')
                                ->label('Список списання')
                                ->color('gray')
                                ->url(fn () => route('print.stock-list', ['date' => $this->data['date'] ?? now()->format('Y-m-d')]))
                                ->openUrlInNewTab(),
                        ])->alignRight(),
                    ])
                ])
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
                        ->getSearchResultsUsing(fn (string $search) =>
                            Ingredient::whereNotIn('id', $excludedIds)
                                ->where('name', 'like', "%{$search}%")
                                ->limit(50)
                                ->pluck('name', 'id')
                        )
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
                if ($currentDish) {
                    $excludedDishIds[] = $currentDish->id;
                }

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

    // === ЕКШН 3: МАСОВА ЗАМІНА ІНГРЕДІЄНТА ===
    public function massReplaceIngredientAction(): Action
    {
        return Action::make('massReplaceIngredient')
            ->label('Масова заміна')
            ->icon('heroicon-m-arrows-right-left')
            ->color('warning')
            ->modalHeading('Масова заміна інгредієнта')
            ->modalDescription('Замінить інгредієнт у ВСІХ клієнтів, у яких він виключений.')
            ->form(function () {
                $conflictedIngredientIds = collect();
                foreach (($this->activeOrders ?? collect()) as $order) {
                    $conflictedIngredientIds = $conflictedIngredientIds->merge(
                        $order->client->ingredientExclusions->pluck('id')
                    );
                }
                $conflictedIngredientIds = $conflictedIngredientIds->unique()->values()->toArray();

                return [
                    Select::make('original_ingredient_id')
                        ->label('Який інгредієнт замінити')
                        ->options(
                            empty($conflictedIngredientIds)
                                ? Ingredient::orderBy('name')->pluck('name', 'id')
                                : Ingredient::whereIn('id', $conflictedIngredientIds)->orderBy('name')->pluck('name', 'id')
                        )
                        ->searchable()
                        ->required(),

                    Select::make('replacement_ingredient_id')
                        ->label('Замінити на')
                        ->options(Ingredient::orderBy('name')->limit(100)->pluck('name', 'id'))
                        ->searchable()
                        ->getSearchResultsUsing(fn (string $search) =>
                            Ingredient::where('name', 'like', "%{$search}%")->limit(50)->pluck('name', 'id')
                        )
                        ->required(),

                    Textarea::make('comment')->label('Коментар')->nullable(),
                ];
            })
            ->action(function (array $data) {
                if ($this->activeOrders === null) {
                    $this->calculate();
                }

                $originalId    = (int) $data['original_ingredient_id'];
                $replacementId = (int) $data['replacement_ingredient_id'];
                $comment       = $data['comment'] ?? null;
                $count         = 0;

                $dishIds = $this->getDishIdsContainingIngredient($originalId);

                foreach (($this->activeOrders ?? collect()) as $order) {
                    if (!$order->client->ingredientExclusions->contains('id', $originalId)) {
                        continue;
                    }
                    foreach ($dishIds as $dishId) {
                        OrderReplacement::updateOrCreate(
                            [
                                'order_id'            => $order->id,
                                'dish_id'             => $dishId,
                                'original_product_id' => $originalId,
                            ],
                            [
                                'replacement_product_id' => $replacementId,
                                'replacement_dish_id'    => null,
                                'comment'                => $comment,
                            ]
                        );
                        $count++;
                    }
                }

                Notification::make()
                    ->title("Масову заміну виконано ({$count} записів)")
                    ->success()
                    ->send();

                $this->calculate();
            });
    }

    // === ЕКШН 4: ЗАСТОСУВАТИ ШАБЛОН ЗАМІН ===
    public function applyBundleAction(): Action
    {
        return Action::make('applyBundle')
            ->label('Застосувати шаблон')
            ->icon('heroicon-m-rectangle-stack')
            ->color('info')
            ->modalHeading('Застосувати шаблон замін')
            ->form(function () {
                return [
                    Select::make('bundle_id')
                        ->label('Оберіть шаблон')
                        ->options(ReplacementBundle::orderBy('name')->pluck('name', 'id'))
                        ->required()
                        ->searchable(),

                    Select::make('scope')
                        ->label('Застосувати до')
                        ->options([
                            'all'    => 'Всі, у кого є виключення з цим інгредієнтом',
                            'single' => 'Конкретне замовлення',
                        ])
                        ->default('all')
                        ->live()
                        ->required(),

                    Select::make('order_id')
                        ->label('Оберіть замовлення')
                        ->options(function () {
                            $selectedDate = $this->data['date'] ?? now()->format('Y-m-d');
                            $targetDate   = \Carbon\Carbon::parse($selectedDate)->addDay()->format('Y-m-d');

                            return Order::whereIn('status', ['new', 'active'])
                                ->whereHas('orderDays', fn ($q) => $q->where('date', $targetDate))
                                ->with('client')
                                ->get()
                                ->pluck('client.name', 'id')
                                ->toArray();
                        })
                        ->searchable()
                        ->visible(fn ($get) => $get('scope') === 'single')
                        ->required(fn ($get) => $get('scope') === 'single'),
                ];
            })
            ->action(function (array $data) {
                if ($this->activeOrders === null) {
                    $this->calculate();
                }

                $bundle = ReplacementBundle::with('items')->find($data['bundle_id']);
                if (!$bundle) return;

                $orders = $data['scope'] === 'all'
                    ? ($this->activeOrders ?? collect())
                    : ($this->activeOrders ?? collect())->where('id', (int) $data['order_id']);

                $count = 0;
                foreach ($orders as $order) {
                    foreach ($bundle->items as $item) {
                        // For "all orders" mode: only apply to clients who actually exclude this ingredient
                        if ($data['scope'] === 'all') {
                            if (!$order->client->ingredientExclusions->contains('id', $item->original_ingredient_id)) {
                                continue;
                            }
                        }

                        $dishIds = $this->getDishIdsContainingIngredient($item->original_ingredient_id);
                        foreach ($dishIds as $dishId) {
                            OrderReplacement::updateOrCreate(
                                [
                                    'order_id'            => $order->id,
                                    'dish_id'             => $dishId,
                                    'original_product_id' => $item->original_ingredient_id,
                                ],
                                [
                                    'replacement_product_id' => $item->replacement_ingredient_id,
                                    'replacement_dish_id'    => null,
                                    'comment'                => "Шаблон: {$bundle->name}",
                                ]
                            );
                            $count++;
                        }
                    }
                }

                Notification::make()
                    ->title("Шаблон «{$bundle->name}» застосовано ({$count} замін)")
                    ->success()
                    ->send();

                $this->calculate();
            });
    }

    // === ХЕЛПЕР: ID КОРЕНЕВИХ СТРАВ МЕНЮ, ЩО МІСТЯТЬ ІНГРЕДІЄНТ (будь-яка глибина вкладеності) ===
    private function getDishIdsContainingIngredient(int $ingredientId): array
    {
        $menuDishIds = collect($this->report)
            ->flatten(1)
            ->pluck('dish_id')
            ->unique()
            ->toArray();

        $result = [];
        foreach ($menuDishIds as $dishId) {
            if ($this->dishContainsIngredient((int) $dishId, $ingredientId, [])) {
                $result[] = (int) $dishId;
            }
        }
        return $result;
    }

    private function dishContainsIngredient(int $dishId, int $ingredientId, array $visited): bool
    {
        if (in_array($dishId, $visited, true)) return false;
        $visited[] = $dishId;

        $rows = DishIngredient::where('dish_id', $dishId)->get();
        foreach ($rows as $row) {
            if ((int) $row->ingredient_id === $ingredientId) return true;
            if ($row->child_dish_id && $this->dishContainsIngredient((int) $row->child_dish_id, $ingredientId, $visited)) {
                return true;
            }
        }
        return false;
    }

    public function calculate(): void
    {
        $selectedDate = $this->data['date'] ?? now()->format('Y-m-d');

        $targetDateObj = Carbon::parse($selectedDate)->addDay();
        $targetDate = $targetDateObj->format('Y-m-d');

        $this->report = [];
        $this->orderPlans = [];

        $cycleDays = (int) Setting::where('key', 'menu_cycle_days')->value('value') ?: 24;
        $startDateStr = Setting::where('key', 'menu_cycle_start_date')->value('value') ?: '2025-01-01';
        $anchorDate = Carbon::parse($startDateStr);

        $diff = abs($targetDateObj->diffInDays($anchorDate));
        $this->currentDayNumber = ($diff % $cycleDays) + 1;

        $this->debugMessage = "🍳 Готуємо сьогодні на завтра: " . $targetDateObj->format('d.m.Y') . " (День циклу №{$this->currentDayNumber})";

        $menu = DailyMenu::where('day_number', $this->currentDayNumber)
            ->with([
                'menuItems.dish.dishIngredients.ingredient.allergens',
                'menuItems.dish.dishIngredients.childDish.dishIngredients.ingredient.allergens',
                'menuItems.mealType'
            ])
            ->first();

        if (!$menu) return;

        $this->activeOrders = Order::whereIn('status', ['new', 'active'])
            ->whereHas('orderDays', function ($query) use ($targetDate) {
                $query->where('date', $targetDate);
            })
            ->with([
                'client.mealTypes',
                'client.ingredientExclusions',
                'client.dishExclusions',
                'replacements.replacementProduct',
                'replacements.replacementDish.dishIngredients.ingredient',
            ])
            ->get();

        if ($this->activeOrders->isEmpty()) return;

        foreach ($this->activeOrders as $order) {
            $this->orderPlans[$order->id] = $this->calculateOrderPlan($order, $menu);
        }

        $sortedMenuItems = $menu->menuItems->sortBy(fn ($item) => $item->mealType?->sort_order ?? 99);

        foreach ($sortedMenuItems as $item) {
            if (!$item->dish) continue;

            $mealName = $item->mealType->name ?? 'Інше';
            $dish = $item->dish;

            $standard = []; // ['order'=>Order,'scale'=>float]
            $custom = [];   // ['order'=>Order,'scale'=>float]
            $commentClients = []; // клієнти лише з коментарем, без реальних змін

            foreach ($this->activeOrders as $order) {
                $plan = $this->orderPlans[$order->id] ?? null;
                if (!$plan) continue;

                $plannedWeight = $this->plannedDishWeight($plan['items'], (int)$dish->id, (int)$item->meal_type_id);
                if ($plannedWeight === null) continue;

                $baseW = (float)($dish->base_weight_g ?? 0);
                $dishScale = ($baseW > 0) ? ((float)$plannedWeight / $baseW) : 0.0;

                // Кастомним вважається лише клієнт з реальними змінами (заміни/виключення)
                // Коментар виробництва — це нотатка, не причина для окремої картки
                $isCustom =
                    $order->replacements->where('dish_id', $dish->id)->isNotEmpty()
                    || $order->client->dishExclusions->contains('id', $dish->id)
                    || $this->checkRecursiveConflict($dish, $order->client->ingredientExclusions);

                // Коментар збираємо для ВСІХ клієнтів (і стандартних, і кастомних)
                if (!empty(trim($order->client->production_comment ?? ''))) {
                    $commentClients[] = [
                        'client_name' => $order->client->name,
                        'order_id'    => $order->id,
                        'comment'     => trim($order->client->production_comment),
                    ];
                }

                if ($isCustom) {
                    $custom[] = ['order' => $order, 'scale' => $dishScale];
                } else {
                    $standard[] = ['order' => $order, 'scale' => $dishScale];
                }
            }

            if (empty($standard) && empty($custom)) continue;

            $standardScales = array_map(fn ($x) => (float)$x['scale'], $standard);

            $standardStructure = $this->calculateIngredientsStructureByScales($dish, $standardScales);
            $standardTotals = $this->calculateTotals($standardStructure);

            $customCards = collect($custom)->map(function ($entry) use ($dish) {
                return $this->buildCustomCard($dish, $entry['order'], (float)$entry['scale']);
            })->toArray();

            $this->report[$mealName][] = [
                'meal_name' => $mealName,
                'dish_id' => $dish->id,
                'dish_name' => $dish->name,

                'standard_count' => count($standard),
                'standard_structure' => $standardStructure,
                'standard_total_netto' => $standardTotals['netto'],
                'standard_total_brutto' => $standardTotals['brutto'],

                'custom_cards' => $customCards,
                'comment_clients' => $commentClients,
            ];
        }
    }

    public function processStockDebiting(): void
    {
        $this->calculate();
        $ingredientsToDebit = [];

        foreach ($this->report as $mealDishes) {
            foreach ($mealDishes as $dishData) {
                // 1. Стандартные порции (уже просуммированы внутри структуры)
                foreach ($dishData['standard_structure'] as $comp) {
                    $this->collectIngredientsRecursive($comp, $ingredientsToDebit);
                }
                
                // 2. Индивидуальные кастомные карточки
                foreach ($dishData['custom_cards'] as $card) {
                    // Пропускаем, если от блюда отказались и нет замены
                    if ($card['dish_excluded'] && empty($card['replacement_dish_id'])) {
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
            // Оптимизация: берем все нужные ингредиенты одним запросом
            $ingredients = Ingredient::whereIn('id', array_keys($ingredientsToDebit))->get()->keyBy('id');

            foreach ($ingredientsToDebit as $id => $totalWeightGrams) {
                $ingredient = $ingredients->get($id);
                if (!$ingredient) continue;

                $unit = mb_strtolower(trim((string)$ingredient->unit));
                $weightToDebit = $totalWeightGrams;

                // Если в рецептах используются граммы, а на складе КГ или Литры — конвертируем
                if (in_array($unit, ['кг', 'kg', 'л', 'l'], true)) {
                    $weightToDebit = $totalWeightGrams / 1000.0;
                }

                $ingredient->decrement('stock', $weightToDebit);
            }
        });
    }

    private function collectIngredientsRecursive(array $component, array &$accumulator): void
    {
        if (($component['type'] ?? null) === 'product') {
            
            $conflict = $component['conflict'] ?? null;
            
            // Если была успешная замена ингредиента, списываем ТОЛЬКО продукт-заменитель
            if (is_array($conflict) && ($conflict['is_resolved'] ?? false) && isset($conflict['replacement']['product_id'])) {
                $id = (int)$conflict['replacement']['product_id'];
                $weight = (float)($conflict['replacement']['brutto'] ?? 0);
            } else {
                // Иначе списываем оригинальный продукт
                $id = (int)($component['product_id'] ?? 0);
                $weight = (float)($component['weight_brutto'] ?? 0);
            }

            if ($id > 0) {
                if (!isset($accumulator[$id])) {
                    $accumulator[$id] = 0.0;
                }
                $accumulator[$id] += $weight;
            }
            return;
        }

        if (($component['type'] ?? null) === 'pf' && isset($component['sub_ingredients']) && is_array($component['sub_ingredients'])) {
            foreach ($component['sub_ingredients'] as $sub) {
                $this->collectIngredientsRecursive($sub, $accumulator);
            }
        }
    }

    private function calculateIngredientsStructureByScales($dish, array $scales): array
    {
        if (empty($scales)) return [];
        $totalScale = array_sum(array_map(fn ($s) => (float)$s, $scales));
        return $this->getHierarchicalIngredients($dish, $totalScale, 1.0, null, false, null);
    }

    private function buildCustomCard($dish, $order, float $scale): array
    {
        $dishExclusion = $order->client->dishExclusions->contains('id', $dish->id);
        $dishReplacement = $order->replacements->where('dish_id', $dish->id)->whereNull('original_product_id')->first();

        $replacementDishName = null;
        $replacementDishId = null;

        if ($dishReplacement && $dishReplacement->replacementDish) {
            $replacementDishName = $dishReplacement->replacementDish->name;
            $replacementDishId = $dishReplacement->replacementDish->id;

            $components = $this->getHierarchicalIngredients(
                $dishReplacement->replacementDish,
                $scale,
                1.0,
                $dishReplacement->replacementDish->id,
                true,
                $order
            );
        } else {
            $components = $this->getHierarchicalIngredients(
                $dish,
                $scale,
                1.0,
                $dish->id,
                true,
                $order
            );
        }

        $totals = $this->calculateTotals($components);
        
        $finalComment = trim($order->client->production_comment ?? '');

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
        $netto = 0.0;
        $brutto = 0.0;

        foreach ($components as $comp) {
            if (($comp['type'] ?? null) === 'pf') {
                $netto += (float)($comp['weight_output'] ?? 0);
                $brutto += (float)($comp['weight_brutto_sum'] ?? 0);
            } else {
                $netto += (float)($comp['weight_netto'] ?? 0);
                $brutto += (float)($comp['weight_brutto'] ?? 0);
            }
        }

        return ['netto' => round($netto), 'brutto' => round($brutto)];
    }

    /**
     * ✅ ВАЖНО: PF масштабируем по выходу (output_weight), а не по сумме закладки.
     * - Для продукта: netto -> brutto через yield%
     * - Для PF: берём долю = (вес_готового_ПФ_в_блюде) / (выход_ПФ)
     */
    private function getHierarchicalIngredients($dish, float $scale, float $subRatio = 1.0, $rootDishId = null, bool $checkConflicts = true, $specificOrder = null): array
    {
        $components = [];
        if (!$dish || !$dish->dishIngredients) return $components;
        if (!$rootDishId) $rootDishId = $dish->id;

        foreach ($dish->dishIngredients as $di) {
            $currentK = $scale * $subRatio;
            $type = mb_strtolower(trim((string)($di->type ?? '')));
            $nettoTotalRaw = (float)($di->net_weight_g ?? 0) * $currentK;

            $conflictData = null;
            $replacementInfo = null;

            $isProduct = in_array($type, ['product', 'продукт'], true);
            $isPf = in_array($type, ['pf', 'напівфабрикат', 'п/ф', 'н/ф'], true);

            if ($checkConflicts && $specificOrder && $isProduct && $di->ingredient) {
                $ingId = (int)$di->ingredient->id;
                if ($specificOrder->client->ingredientExclusions->contains('id', $ingId)) {
                    $rep = $specificOrder->replacements
                        ->where('dish_id', $rootDishId)
                        ->where('original_product_id', $ingId)
                        ->first();

                    if ($rep && $rep->replacementProduct) {
                        $newYield = (float)($rep->replacementProduct->yield_percent ?: 100);
                        if ($newYield <= 0) $newYield = 100;

                        $replacementInfo = [
                            'name' => $rep->replacementProduct->name,
                            'netto' => round($nettoTotalRaw, 1),
                            'brutto' => round(($nettoTotalRaw * 100) / $newYield, 1),
                            'unit' => $rep->replacementProduct->unit ?? 'г',
                            'product_id' => (int)$rep->replacementProduct->id,
                        ];
                    }

                    $conflictData = [
                        'is_resolved'     => (bool)$replacementInfo,
                        'replacement'     => $replacementInfo,
                        'original_ing_id' => $ingId,
                        'allergen'        => $di->ingredient->allergens->pluck('name')->join(', ') ?: null,
                    ];
                }
            }

            // 1) PRODUCT
            if ($isProduct && $di->ingredient) {
                $yield = (float)($di->ingredient->yield_percent ?: 100);
                if ($yield <= 0) $yield = 100;

                $components[] = [
                    'type' => 'product',
                    'name' => $di->ingredient->name,
                    'weight_netto' => round($nettoTotalRaw, 1),
                    'weight_brutto' => round(($nettoTotalRaw * 100) / $yield, 1),
                    'unit' => $di->ingredient->unit ?? 'г',
                    'conflict' => $conflictData,
                    'product_id' => (int)$di->ingredient->id,
                ];

                continue;
            }

            // 2) PF / CHILD DISH
            if ($isPf && $di->childDish) {
                // ✅ ВАЖНО: доля ПФ считается от ВЫХОДА ПФ (output_weight),
                // потому что net_weight_g в блюде — это "сколько ГОТОВОГО ПФ кладем".
                $pfTotals = $di->childDish->calculated_totals;
                $pfOutput = (float)($pfTotals['output_weight'] ?? 0);

                if ($pfOutput <= 0) {
                    // если ПФ некорректный — пропускаем, чтобы не ломать математику
                    continue;
                }

                $pfRatio = ((float)($di->net_weight_g ?? 0)) / $pfOutput;

                $subIngredients = $this->getHierarchicalIngredients(
                    $di->childDish,
                    $scale,
                    ($pfRatio * $subRatio),
                    $rootDishId,
                    $checkConflicts,
                    $specificOrder
                );

                $sumNetto = 0.0;
                $sumBrutto = 0.0;

                foreach ($subIngredients as $s) {
                    $sumNetto += (float)($s['weight_netto'] ?? ($s['weight_output'] ?? 0));
                    $sumBrutto += (float)($s['weight_brutto'] ?? ($s['weight_brutto_sum'] ?? 0));
                }

                $components[] = [
                    'type' => 'pf',
                    'name' => $di->childDish->name,
                    // в отчёте для ПФ показываем "сколько готового ПФ нужно"
                    'weight_output' => round($nettoTotalRaw, 1),
                    'weight_netto_sum' => round($sumNetto, 1),
                    'weight_brutto_sum' => round($sumBrutto, 1),
                    // 👇 ДОДАНО: Ключі weight_netto та weight_brutto, щоб шаблон бачив вагу!
                    'weight_netto' => round($sumNetto, 1),
                    'weight_brutto' => round($sumBrutto, 1),
                    'sub_ingredients' => $subIngredients
                ];
            }
        }

        return $components;
    }

    private function checkRecursiveConflict($dish, $exclusions): bool
    {
        if (!$dish || !$dish->dishIngredients) return false;

        foreach ($dish->dishIngredients as $di) {
            if ($di->ingredient_id && $exclusions->contains('id', $di->ingredient_id)) {
                return true;
            }
            if ($di->child_dish_id && $di->childDish) {
                if ($this->checkRecursiveConflict($di->childDish, $exclusions)) return true;
            }
        }
        return false;
    }

    // =========================================================
    // ✅ ПЛАН РАЦИОНА
    // =========================================================

    private function calculateOrderPlan(Order $order, DailyMenu $menu): array
    {
        $targetKcal = (float)($order->calories ?? 0);
        if ($targetKcal <= 0) {
            return ['items' => [], 'totals' => ['kcal' => 0, 'prot' => 0, 'fat' => 0, 'carb' => 0]];
        }

        $clientMealTypeIds = $order->client?->mealTypes?->pluck('id')->toArray() ?? [];

        $availableItems = $menu->menuItems
            ->filter(fn ($item) => $item->dish && in_array($item->meal_type_id, $clientMealTypeIds, true))
            ->sortBy(fn ($item) => $item->mealType?->sort_order ?? 99)
            ->values();

        if ($availableItems->isEmpty()) {
            return ['items' => [], 'totals' => ['kcal' => 0, 'prot' => 0, 'fat' => 0, 'carb' => 0]];
        }

        $expectedDishes = $this->expectedDishCount((int)$targetKcal);
        $selected = $availableItems->take($expectedDishes);
        if ($selected->isEmpty()) {
            return ['items' => [], 'totals' => ['kcal' => 0, 'prot' => 0, 'fat' => 0, 'carb' => 0]];
        }

        $byMeal = $selected->groupBy('meal_type_id');

        $totals = ['kcal' => 0.0, 'prot' => 0.0, 'fat' => 0.0, 'carb' => 0.0];
        $itemsOut = [];

        foreach ($byMeal as $mealTypeId => $items) {
            $firstItem = $items->first();

            $p = $firstItem->custom_energy_percent !== null
                ? (float) $firstItem->custom_energy_percent
                : (float) ($firstItem->mealType?->energy_percent ?? 0);

            $mealKcal = ($p > 0)
                ? $targetKcal * ($p / 100.0)
                : $targetKcal * (1.0 / max(1, $byMeal->count()));

            $countInMeal = max(1, $items->count());
            $kcalPerDish = $mealKcal / $countInMeal;

            foreach ($items as $mi) {
                $dish = $mi->dish;
                if (!$dish) continue;

                $kcalPer100 = $this->dishKcalPer100g($dish);

                $weight = ($kcalPer100 > 0)
                    ? (int) round(($kcalPerDish / $kcalPer100) * 100.0)
                    : 0;

                // ✅ БЖУ берем из calculated_totals (один источник правды)
                $dt = $dish->calculated_totals;
                $outW = (float)($dt['output_weight'] ?? ($dish->base_weight_g ?? 0));
                $outW = $outW > 0 ? $outW : 1;

                $protPerG = (float)($dt['prot'] ?? 0) / $outW;
                $fatPerG  = (float)($dt['fat'] ?? 0) / $outW;
                $carbPerG = (float)($dt['carb'] ?? 0) / $outW;

                $totals['kcal'] += ($weight * $kcalPer100 / 100.0);
                $totals['prot'] += ($weight * $protPerG);
                $totals['fat']  += ($weight * $fatPerG);
                $totals['carb'] += ($weight * $carbPerG);

                $itemsOut[] = [
                    'dish_id' => (int)$dish->id,
                    'meal_type_id' => (int)$mealTypeId,
                    'weight' => (int)$weight,
                ];
            }
        }

        return ['items' => $itemsOut, 'totals' => $totals];
    }

    private function expectedDishCount(int $kcal): int
    {
        if ($kcal < 1200) return 3;
        if ($kcal < 1500) return 4;
        return 5;
    }

    private function dishKcalPer100g($dish): float
    {
        $baseW = (float)($dish->base_weight_g ?? 0);
        // total_kcal у тебя аксесор из Dish->calculated_totals, это ок
        $totalKcal = (float)($dish->total_kcal ?? 0);

        if ($baseW <= 0 || $totalKcal <= 0) return 0.0;

        return ($totalKcal / $baseW) * 100.0;
    }

    private function plannedDishWeight(array $items, int $dishId, int $mealTypeId): ?int
    {
        foreach ($items as $it) {
            if ((int)$it['dish_id'] === $dishId && (int)$it['meal_type_id'] === $mealTypeId) {
                return (int)$it['weight'];
            }
        }
        return null;
    }
}