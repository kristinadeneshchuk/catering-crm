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
use App\Services\Kitchen\KitchenStockDebit;
use App\Services\Kitchen\ProductionPlanBuilder;

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
        $cookDate = Carbon::parse($dateParam);

        $debit = app(KitchenStockDebit::class);
        $pending = $debit->pendingFoodDates($cookDate);
        $isClosed = empty($pending);

        $label = match (true) {
            $cookDate->isSaturday() => 'У суботу кухня не готує — неділю списано в п\'ятницю',
            $isClosed               => "Зміну за {$dateParam} вже закрито",
            default                 => 'Закрити зміну та списати склад',
        };

        return [
            Action::make('debit_stock')
                ->label($label)
                ->tooltip('Фінальний крок дня: списує зі складу продукти й упаковку за нормою (план × техкарти, разом з індивідуальними меню). У п\'ятницю — одразу на суботу й неділю. Якщо не натиснути, зміну закриє автоматика о 06:00 наступного дня.')
                ->icon($isClosed ? 'heroicon-o-lock-closed' : 'heroicon-o-archive-box-arrow-down')
                ->color($isClosed ? 'warning' : 'danger')
                ->disabled($isClosed)
                ->requiresConfirmation(fn () => !$isClosed)
                ->modalHeading('Підтвердити списання залишків?')
                ->modalDescription('Буде створено документ списання за нормою (брутто інгредієнтів і упаковка). Розберись із конфліктами до натискання — списання піде по тому, що зараз у таблиці.')
                ->action(function () use ($debit, $cookDate, $dateParam) {
                    $plan = $debit->plan($cookDate);

                    if (empty($plan['food_dates'])) {
                        Notification::make()->title('Операцію скасовано')->body("Зміну за {$dateParam} вже закрито.")->warning()->send();
                        return;
                    }

                    if ($plan['blocking']) {
                        $missing = collect($plan['days'])->flatMap(fn ($d, $food) => array_map(
                            fn ($m) => Carbon::parse($food)->format('d.m') . " — «{$m['plan']}», день {$m['day_number']}",
                            $d['missing'],
                        ))->implode('; ');

                        Notification::make()->title('Списання не проведено')->body("Немає меню: {$missing}. Додай меню й спробуй ще раз.")->danger()->persistent()->send();
                        return;
                    }

                    try {
                        $debit->post($plan, auth()->id());
                    } catch (\Illuminate\Database\QueryException) {
                        Notification::make()->title('Операцію скасовано')->body("Зміну за {$dateParam} щойно закрили.")->warning()->send();
                        return;
                    }

                    Notification::make()
                        ->title('Зміну закрито')
                        ->body('Списано на ' . number_format($plan['total'], 0, '.', ' ') . ' ₴'
                            . ($plan['skipped'] ? ' · без перерахунку: ' . collect($plan['skipped'])->pluck('name')->implode(', ') : ''))
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
                                ->tooltip('Цей самий звіт, але у вигляді для друку кухні.')
                                ->color('warning')
                                ->url(fn () => route('print.production-report', ['date' => $this->data['date'] ?? now()->format('Y-m-d')]))
                                ->openUrlInNewTab(),
                            \Filament\Forms\Components\Actions\Action::make('print_stock')
                                ->label('Список списання')
                                ->tooltip('Перелік, що саме і в якій кількості буде списано зі складу за цей день.')
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
            ->tooltip('Замінює інгредієнт одразу в усіх стравах дня. Зручно, коли продукту немає взагалі — не треба міняти в кожній страві окремо.')
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
            ->tooltip('Застосовує збережений набір замін (шаблон) до всього дня одним натисканням.')
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
        $targetDate = Carbon::parse($selectedDate)->addDay()->format('Y-m-d');

        $built = app(ProductionPlanBuilder::class)->build($targetDate);

        $this->report = $built['report'];
        $this->individualClients = [];
        $this->missingPlans = $built['missing_plans'];
        $this->activeOrders = $built['orders'];

        if ($built['day_number'] !== null) {
            $this->currentDayNumber = $built['day_number'];
            $this->debugMessage = $built['debug'];
        }
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
}
