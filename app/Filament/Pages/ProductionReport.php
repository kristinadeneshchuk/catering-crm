<?php

namespace App\Filament\Pages;

use Filament\Pages\Page;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Contracts\HasForms;
use App\Models\DailyMenu;
use App\Models\MenuPlan;
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
use App\Traits\CalculatesOrderPlan;

class ProductionReport extends Page implements HasForms
{
    use InteractsWithForms, InteractsWithActions, CalculatesOrderPlan;

    protected static ?string $navigationIcon = 'heroicon-o-presentation-chart-line';
    protected static ?string $navigationLabel = 'План виробництва';
    protected static ?string $title = 'План виробництва';
    protected static string $view = 'filament.pages.production-report';

    public ?array $data = [];
    public array $report = [];
    public array $individualClients = [];
    public array $missingPlans = [];
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
                ->modalDescription('Система автоматично відніме вагу БРУТТО всіх інгредієнтів та кількість упаковки зі складу. Цю дію неможливо скасувати.')
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

                                $plans = \App\Models\MenuPlan::orderBy('sort_order')->orderBy('id')->get();
                                $lines = $plans->map(function ($plan) use ($targetDateObj) {
                                    return "<strong>{$plan->name}</strong>: день циклу №" . $plan->globalDayFor($targetDateObj);
                                })->implode(' · ');

                                return new HtmlString(
                                    "<div class='p-2 bg-primary-500/10 border border-primary-500 rounded-lg text-primary-600'>
                                        👨‍🍳 Кухня готує сьогодні на <strong>завтра (" . $targetDateObj->format('d.m.Y') . ")</strong>.
                                        <br>" . ($lines ?: 'Немає планів меню.') . "
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
            'reportData' => $this->report,   // [$planId => ['plan'=>..,'day_number'=>..,'meals'=>[..],'individuals'=>[..]]]
            'dayNumber'  => $this->currentDayNumber,
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

    // === ЕКШН: Примусово одобрити інгредієнт ===
    // === ЕКШН: Застосувати пропозицію з шаблону клієнта ===
    public function applyBundleSuggestionAction(): Action
    {
        return Action::make('applyBundleSuggestion')
            ->label('Застосувати пропозицію')
            ->requiresConfirmation()
            ->modalHeading('Застосувати заміну з шаблону?')
            ->modalDescription('Інгредієнт буде замінено згідно прив\'язаного до клієнта шаблону.')
            ->action(function (array $arguments) {
                OrderReplacement::updateOrCreate(
                    [
                        'order_id'            => $arguments['order_id'],
                        'dish_id'             => $arguments['dish_id'],
                        'original_product_id' => $arguments['product_id'],
                    ],
                    [
                        'replacement_product_id' => $arguments['replacement_product_id'],
                        'replacement_dish_id'    => null,
                        'force_approved'         => false,
                        'comment'                => 'Шаблон: ' . ($arguments['bundle_name'] ?? '—'),
                    ]
                );
                Notification::make()->title('Заміну з шаблону застосовано')->success()->send();
                $this->calculate();
            });
    }

    public function forceApproveIngredientAction(): Action
    {
        return Action::make('forceApproveIngredient')
            ->label('Одобрити')
            ->requiresConfirmation()
            ->modalHeading('Примусово дозволити інгредієнт?')
            ->modalDescription('Інгредієнт буде додано до порції незважаючи на виключення клієнта.')
            ->action(function (array $arguments) {
                OrderReplacement::updateOrCreate(
                    [
                        'order_id'            => $arguments['order_id'],
                        'dish_id'             => $arguments['dish_id'],
                        'original_product_id' => $arguments['product_id'],
                    ],
                    [
                        'replacement_product_id' => null,
                        'replacement_dish_id'    => null,
                        'force_approved'         => true,
                        'comment'                => 'Примусово одобрено',
                    ]
                );
                Notification::make()->title('Інгредієнт одобрено')->success()->send();
                $this->calculate();
            });
    }

    // === ЕКШН: Примусово одобрити страву ===
    public function forceApproveDishAction(): Action
    {
        return Action::make('forceApproveDish')
            ->label('Одобрити страву')
            ->requiresConfirmation()
            ->modalHeading('Примусово дозволити страву?')
            ->modalDescription('Страва буде додана до виробництва незважаючи на виключення клієнта.')
            ->action(function (array $arguments) {
                OrderReplacement::updateOrCreate(
                    [
                        'order_id'            => $arguments['order_id'],
                        'dish_id'             => $arguments['dish_id'],
                        'original_product_id' => null,
                    ],
                    [
                        'replacement_product_id' => null,
                        'replacement_dish_id'    => null,
                        'force_approved'         => true,
                        'comment'                => 'Примусово одобрено',
                    ]
                );
                Notification::make()->title('Страву одобрено')->success()->send();
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
                    $excludedIds = $this->effectiveExclusions($order)->pluck('id')->toArray();
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
                            return Dish::whereNotIn('id', $excludedDishIds)
                                ->orderBy('name')
                                ->pluck('name', 'id');
                        })
                        ->getSearchResultsUsing(function (string $search) use ($excludedDishIds) {
                            return Dish::whereNotIn('id', $excludedDishIds)
                                ->where('name', 'like', "%{$search}%")
                                ->orderBy('name')
                                ->limit(50)
                                ->pluck('name', 'id');
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
                        $this->effectiveExclusions($order)->pluck('id')
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
                    if (!$this->effectiveExclusions($order)->contains('id', $originalId)) {
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

                            return Order::feedingOn($targetDate)
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
                            if (!$this->effectiveExclusions($order)->contains('id', $item->original_ingredient_id)) {
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
        // $this->report = [planId => ['plan'=>..,'meals'=>[mealName => [dishes...]],'individuals'=>...]]
        $menuDishIds = collect($this->report)
            ->flatMap(fn ($planData) => collect($planData['meals'] ?? [])->flatten(1))
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
        $this->individualClients = [];
        $this->orderPlans = [];
        $this->missingPlans = [];

        $this->activeOrders = Order::feedingOn($targetDate)
            ->with([
                'client.mealTypes',
                'client.ingredientExclusions',
                'client.dishExclusions',
                'client.replacementBundles.items.originalIngredient',
                'client.replacementBundles.items.replacementIngredient',
                'ingredientExclusions',
                'menuPlan',
                'replacements.replacementProduct',
                'replacements.replacementDish.dishIngredients.ingredient',
                'replacements.replacementDish.dishIngredients.childDish.dishIngredients.ingredient',
                'projectData',
            ])
            ->get();

        if ($this->activeOrders->isEmpty()) return;

        // Групуємо замовлення по планах меню
        $ordersByPlan = $this->activeOrders->groupBy(fn ($o) => $o->effectiveMenuPlan()?->id ?? 0);

        $debugLines = [];

        foreach ($ordersByPlan as $planId => $planOrders) {
            $plan = $planOrders->first()->effectiveMenuPlan();
            if (!$plan) continue;

            $dayNumber = $plan->globalDayFor($targetDateObj);
            $debugLines[] = "{$plan->name} — день {$dayNumber}";

            $menu = DailyMenu::where('menu_plan_id', $plan->id)
                ->where('day_number', $dayNumber)
                ->with([
                    'menuItems.dish.dishIngredients.ingredient.allergens',
                    'menuItems.dish.dishIngredients.childDish.dishIngredients.ingredient.allergens',
                    'menuItems.mealType',
                ])
                ->first();

            if (!$menu) {
                $this->missingPlans[] = [
                    'plan'         => $plan,
                    'day_number'   => $dayNumber,
                    'orders_count' => $planOrders->count(),
                    'client_names' => $planOrders->map(fn ($o) => $o->client?->name)->filter()->unique()->take(5)->values()->all(),
                ];
                continue;
            }

            // Розраховуємо план для кожного замовлення в цьому плані меню
            foreach ($planOrders as $order) {
                $this->orderPlans[$order->id] = $this->calculateOrderPlan($order, $menu, $targetDate);
            }

            $planMeals = [];
            $sortedMenuItems = $menu->menuItems->sortBy(fn ($item) => $item->mealType?->sort_order ?? 99);

            foreach ($sortedMenuItems as $item) {
                if (!$item->dish) continue;

                $mealName = $item->mealType->name ?? 'Інше';
                $dish = $item->dish;

                $standard = [];
                $custom = [];
                $commentClients = [];

                foreach ($planOrders as $order) {
                    if ($order->menu_type === 'individual') continue;

                    $orderPlan = $this->orderPlans[$order->id] ?? null;
                    if (!$orderPlan) continue;

                    $plannedWeight = $this->plannedDishWeight($orderPlan['items'], (int)$dish->id, (int)$item->meal_type_id);
                    if ($plannedWeight === null) continue;

                    $baseW = (float)($dish->base_weight_g ?? 0);
                    $dishScale = ($baseW > 0) ? ((float)$plannedWeight / $baseW) : 0.0;

                    $isCustom = $this->isCustomForDish($order, $dish);

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

                $planMeals[$mealName][] = [
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

            // === ІНДИВІДУАЛЬНІ КЛІЄНТИ цього плану ===
            // Preload all dishes for individual clients in one query to avoid N+1
            $individualDishIds = [];
            foreach ($planOrders as $order) {
                if ($order->menu_type !== 'individual') continue;
                $orderPlan = $this->orderPlans[$order->id] ?? null;
                if (!$orderPlan || empty($orderPlan['items'])) continue;
                foreach ($orderPlan['items'] as $item) {
                    $individualDishIds[] = $item['dish_id'];
                }
            }
            $individualDishesMap = collect();
            if (!empty($individualDishIds)) {
                $individualDishesMap = Dish::with([
                    'dishIngredients.ingredient.allergens',
                    'dishIngredients.childDish.dishIngredients.ingredient.allergens',
                ])->whereIn('id', array_unique($individualDishIds))->get()->keyBy('id');
            }

            $planIndividuals = [];
            foreach ($planOrders as $order) {
                if ($order->menu_type !== 'individual') continue;

                $orderPlan = $this->orderPlans[$order->id] ?? null;
                if (!$orderPlan || empty($orderPlan['items'])) continue;

                $oid = $order->id;
                $meals = [];

                foreach ($orderPlan['items'] as $item) {
                    $dish = $individualDishesMap->get($item['dish_id']);
                    if (!$dish) continue;

                    $weight = (int)$item['weight'];
                    $baseW  = (float)($dish->base_weight_g ?? 0);
                    $scale  = $baseW > 0 ? $weight / $baseW : 0.0;

                    // Клієнтам на індивідуальному меню картка малювалась без
                    // перевірки виключень — кухня бачила звичайну рецептуру навіть
                    // тоді, коли в анкеті стоїть «не їсть». Рахуємо так само, як
                    // для циклічних карток: з виключеннями і рішеннями менеджера.
                    $components = $this->getHierarchicalIngredients($dish, $scale, 1.0, null, true, $order);
                    $totals     = $this->calculateTotals($components);

                    $meals[] = [
                        'meal'          => $item['meal'],
                        'dish_name'     => $dish->name,
                        'components'    => $components,
                        'total_netto'   => $totals['netto'],
                        'total_brutto'  => $totals['brutto'],
                    ];
                }

                $planIndividuals[$oid] = [
                    'client_label' => '#' . $order->client->id . ' ' . $order->client->name,
                    'calories'     => (int)($order->calories ?? 0),
                    'project'      => $order->projectData?->name ?? ucfirst($order->project ?? ''),
                    'meals'        => $meals,
                ];
            }

            if (empty($planMeals) && empty($planIndividuals)) continue;

            $this->report[$plan->id] = [
                'plan'        => $plan,
                'day_number'  => $dayNumber,
                'meals'       => $planMeals,
                'individuals' => $planIndividuals,
            ];
        }

        // Дефолтний день циклу — для зворотної сумісності з заголовками
        $defaultPlan = MenuPlan::default();
        $this->currentDayNumber = $defaultPlan ? $defaultPlan->globalDayFor($targetDateObj) : 0;

        $this->debugMessage = "🍳 Готуємо сьогодні на завтра: " . $targetDateObj->format('d.m.Y')
            . ($debugLines ? ' · ' . implode(' · ', $debugLines) : '');
    }

    public function processStockDebiting(): void
    {
        $this->calculate();
        $ingredientsToDebit = [];

        // 1+2. Страви всіх планів меню (стандарт + індивідуальні картки в межах плану)
        foreach ($this->report as $planData) {
            foreach (($planData['meals'] ?? []) as $mealDishes) {
                foreach ($mealDishes as $dishData) {
                    foreach ($dishData['standard_structure'] as $comp) {
                        $this->collectIngredientsRecursive($comp, $ingredientsToDebit);
                    }
                    foreach ($dishData['custom_cards'] as $card) {
                        if ($card['dish_excluded'] && empty($card['replacement_dish_id'])) continue;
                        foreach ($card['components'] as $comp) {
                            $this->collectIngredientsRecursive($comp, $ingredientsToDebit);
                        }
                    }
                }
            }

            // 3. Індивідуальні клієнти цього плану
            foreach (($planData['individuals'] ?? []) as $clientData) {
                foreach ($clientData['meals'] as $meal) {
                    foreach ($meal['components'] as $comp) {
                        $this->collectIngredientsRecursive($comp, $ingredientsToDebit);
                    }
                }
            }
        }

        if (empty($ingredientsToDebit)) {
            Notification::make()->title('Немає даних для списання')->warning()->send();
            return;
        }

        // Збираємо упаковку для списання
        $packagingToDebit = $this->collectPackagingToDebit();

        DB::transaction(function () use ($ingredientsToDebit, $packagingToDebit) {
            // --- Інгредієнти ---
            $ingredients = Ingredient::whereIn('id', array_keys($ingredientsToDebit))->get()->keyBy('id');

            foreach ($ingredientsToDebit as $id => $totalWeightGrams) {
                $ingredient = $ingredients->get($id);
                if (!$ingredient) continue;

                $unit = mb_strtolower(trim((string)$ingredient->unit));
                $weightToDebit = $totalWeightGrams;

                // Якщо на складі КГ або Літри — конвертуємо з грамів
                if (in_array($unit, ['кг', 'kg', 'л', 'l'], true)) {
                    $weightToDebit = $totalWeightGrams / 1000.0;
                }

                $ingredient->decrement('stock', $weightToDebit);
            }

            // --- Упаковка ---
            if (!empty($packagingToDebit)) {
                $packagings = \App\Models\Packaging::whereIn('id', array_keys($packagingToDebit))->get()->keyBy('id');
                foreach ($packagingToDebit as $packagingId => $qty) {
                    $packaging = $packagings->get($packagingId);
                    if (!$packaging) continue;
                    $packaging->decrement('stock', $qty);
                }
            }
        });
    }

    /**
     * Розраховує кількість кожного виду упаковки для поточного дня
     * на основі активних замовлень (використовує PackagingService).
     */
    private function collectPackagingToDebit(): array
    {
        if (!$this->activeOrders || $this->activeOrders->isEmpty()) return [];

        $selectedDate  = $this->data['date'] ?? now()->format('Y-m-d');
        $targetDateObj = Carbon::parse($selectedDate)->addDay();

        $allPackaging = \App\Models\Packaging::whereNotNull('packaging_type')->get()->keyBy('id');
        $service = new \App\Services\PackagingService();

        // Групуємо замовлення по планах меню — кожен план має свій день циклу і своє меню
        $ordersByPlan = $this->activeOrders->groupBy(fn ($o) => $o->effectiveMenuPlan()?->id ?? 0);

        $toDebit = [];

        foreach ($ordersByPlan as $planId => $planOrders) {
            $plan = $planOrders->first()->effectiveMenuPlan();
            if (!$plan) continue;

            $dayNum = $plan->globalDayFor($targetDateObj);

            $menu = DailyMenu::with([
                'menuItems.dish.dishIngredients.childDish',
                'menuItems.mealType',
            ])
                ->where('menu_plan_id', $plan->id)
                ->where('day_number', $dayNum)
                ->first();

            if (!$menu) continue;

            $summary = $service->getDailyPackagingSummary($planOrders, $menu, $allPackaging, $targetDateObj->format('Y-m-d'));

            foreach ($summary as $packagingId => $item) {
                $qty = (int) round($item['total_qty'] ?? 0);
                if ($qty > 0) {
                    $toDebit[$packagingId] = ($toDebit[$packagingId] ?? 0) + $qty;
                }
            }
        }

        return $toDebit;
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
        $dishForcedApproval = $order->replacements
            ->where('dish_id', $dish->id)
            ->whereNull('original_product_id')
            ->where('force_approved', true)
            ->first();

        $dishExclusion = !$dishForcedApproval && $order->client->dishExclusions->contains('id', $dish->id);
        $dishReplacement = $order->replacements->where('dish_id', $dish->id)->whereNull('original_product_id')->where('force_approved', false)->first();

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
            'excluded_ingredients' => $this->effectiveExclusions($order)->pluck('name')->values()->all(),
            'excluded_dishes' => $order->client->dishExclusions->pluck('name')->values()->all(),
            'bundles' => $order->client->replacementBundles->pluck('name')->values()->all(),
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
                if ($this->effectiveExclusions($specificOrder)->contains('id', $ingId)) {
                    $rep = $specificOrder->replacements
                        ->where('dish_id', $rootDishId)
                        ->where('original_product_id', $ingId)
                        ->first();

                    if ($rep && $rep->force_approved) {
                        // Примусово одобрено — показуємо як одобрений
                        $conflictData = [
                            'is_resolved'      => true,
                            'is_force_approved' => true,
                            'replacement'      => null,
                            'original_ing_id'  => $ingId,
                            'allergen'         => null,
                        ];
                    } else {
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
                            'is_resolved'       => (bool)$replacementInfo,
                            'replacement'       => $replacementInfo,
                            'original_ing_id'   => $ingId,
                            'allergen'          => $di->ingredient->allergens->pluck('name')->join(', ') ?: null,
                            'bundle_suggestion' => $replacementInfo ? null : $this->getBundleSuggestion($specificOrder, $ingId),
                        ];
                    }
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

    /**
     * Ефективні виключення інгредієнтів для замовлення:
     * ручні `ingredientExclusions` ∪ `original_ingredient_id` із прив'язаних до клієнта шаблонів.
     * Повертає колекцію об'єктів Ingredient (для сумісності з існуючим API `->contains('id', $x)`).
     */
    private function effectiveExclusions($order)
    {
        return $order->effectiveExcludedIngredients();
    }

    /**
     * Якщо інгредієнт `$ingId` присутній у якомусь з прив'язаних до клієнта шаблонів —
     * повернути пропозицію заміни з цього шаблону. Лише підказка, нічого не зберігає.
     * Повертає [`name`, `product_id`, `bundle_name`] або null.
     */
    private function getBundleSuggestion($order, int $ingId): ?array
    {
        foreach (($order->client->replacementBundles ?? collect()) as $bundle) {
            foreach ($bundle->items as $item) {
                if ((int) $item->original_ingredient_id === $ingId && $item->replacementIngredient) {
                    return [
                        'name'        => $item->replacementIngredient->name,
                        'product_id'  => (int) $item->replacementIngredient->id,
                        'bundle_name' => $bundle->name,
                    ];
                }
            }
        }
        return null;
    }

    // =========================================================
    // ✅ ПЛАН РАЦИОНА
    // =========================================================


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