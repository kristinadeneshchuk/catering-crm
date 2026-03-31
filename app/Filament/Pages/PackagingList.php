<?php

namespace App\Filament\Pages;

use Filament\Pages\Page;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use App\Models\DailyMenu;
use App\Models\Order;
use App\Models\Setting;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Form;
use Filament\Actions\Action;
use Carbon\Carbon;
use Illuminate\Support\HtmlString;

class PackagingList extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-list';
    protected static ?string $navigationLabel = 'Фасувальний лист';
    protected static ?string $title = 'Фасувальний лист';
    protected static string $view = 'filament.pages.packaging-list';

    public ?array $data = [];
    public array $report = [];
    public ?string $debugMessage = null;

    /** @var array<int, array{items: array, totals?: array}> */
    private array $orderPlans = [];

    public function mount(): void
    {
        $this->form->fill(['date' => request()->query('date', now()->format('Y-m-d'))]);
        $this->calculate();
    }

    protected function getHeaderActions(): array
    {
        return [
            // 🔥 ПЕРШИЙ ВИПАДАЮЧИЙ СПИСОК: Друк
            \Filament\Actions\ActionGroup::make([
                Action::make('print_stickers')
                    ->label('1. Стікери')
                    ->icon('heroicon-o-tag')
                    ->color('gray')
                    ->url(fn () => route('print.stickers', ['date' => $this->data['date'] ?? now()->format('Y-m-d')]))
                    ->openUrlInNewTab(),

                Action::make('print_mini_manifest')
                    ->label('2. На пакет')
                    ->icon('heroicon-o-document-text')
                    ->color('info')
                    ->url(fn () => route('print.mini-manifest', ['date' => $this->data['date'] ?? now()->format('Y-m-d')]))
                    ->openUrlInNewTab(),

                Action::make('print_manifest')
                    ->label('3. В пакет (з меню)')
                    ->icon('heroicon-o-shopping-bag')
                    ->color('warning')
                    ->url(fn () => route('print.manifest', ['date' => $this->data['date'] ?? now()->format('Y-m-d')]))
                    ->openUrlInNewTab(),
            ])
            ->label('Друк маркування')
            ->icon('heroicon-o-printer')
            ->button()
            ->color('gray'),

            // 🔥 ДРУГИЙ ВИПАДАЮЧИЙ СПИСОК: Логістика
            \Filament\Actions\ActionGroup::make([
                Action::make('download_logistics_evening')
                    ->label('Вечір (Сьогодні)')
                    ->icon('heroicon-o-moon')
                    ->color('danger')
                    ->url(fn () => route('print.logistics', [
                        'date' => $this->data['date'] ?? now()->format('Y-m-d'),
                        'shift' => 'evening' 
                    ])),

                Action::make('download_logistics_morning')
                    ->label('Ранок (Завтра)')
                    ->icon('heroicon-o-sun')
                    ->color('success')
                    ->url(fn () => route('print.logistics', [
                        'date' => $this->data['date'] ?? now()->format('Y-m-d'),
                        'shift' => 'morning' 
                    ])),
            ])
            ->label('Логістика (Excel)')
            ->icon('heroicon-o-truck')
            ->button()
            ->color('primary'),
        ];
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            Grid::make(2)->schema([
                DatePicker::make('date')
                    ->label('Дата фасування (сьогодні)')
                    ->required()
                    ->live()
                    ->afterStateUpdated(fn () => $this->calculate()),

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
                                "<div class='p-4 bg-gray-900 border border-gray-700 rounded-lg text-white'>
                                    Фасування на <strong class='text-primary-400'>завтра (" . $targetDateObj->format('d.m.Y') . ")</strong>.
                                    <br> Це буде <strong class='text-primary-400'>" . $dayNum . "-й день</strong> циклу меню.
                                </div>"
                            );
                        }),
                        
                    \Filament\Forms\Components\Actions::make([
                        \Filament\Forms\Components\Actions\Action::make('print_view')
                            ->label('Відкрити версію для друку')
                            ->color('warning')
                            ->url(fn () => route('print.packaging-list', ['date' => $this->data['date'] ?? now()->format('Y-m-d')]))
                            ->openUrlInNewTab()
                    ])->alignRight(),
                ])
            ])
        ])->statePath('data');
    }

    public function calculate(): void
    {
        $selectedDate  = $this->data['date'] ?? now()->format('Y-m-d');
        $targetDateObj = Carbon::parse($selectedDate)->addDay();
        $targetDate    = $targetDateObj->format('Y-m-d');

        $this->report = [];
        $this->orderPlans = [];
        $this->debugMessage = null;

        $cycleDays    = (int) Setting::where('key', 'menu_cycle_days')->value('value') ?: 24;
        $startDateStr = Setting::where('key', 'menu_cycle_start_date')->value('value') ?: '2025-01-01';
        $anchorDate   = Carbon::parse($startDateStr);

        $diff = abs($targetDateObj->diffInDays($anchorDate));
        $globalDay = ($diff % $cycleDays) + 1;

        $menu = DailyMenu::where('day_number', $globalDay)
            ->with([
                'menuItems.dish.dishIngredients.ingredient',
                'menuItems.dish.dishIngredients.childDish.dishIngredients.ingredient',
                'menuItems.mealType'
            ])
            ->first();

        if (!$menu) {
            $this->debugMessage = "Меню на {$targetDateObj->format('d.m.Y')} (день циклу {$globalDay}) не знайдено";
            return;
        }

        // 🔥 ПРИБРАНО ФІЛЬТР ЗА СТАТУСОМ. Тепер беремо всі замовлення, для яких є день фасування.
        $orders = Order::whereHas('orderDays', fn ($q) => $q->where('date', $targetDate))
            ->with([
                'client.mealTypes',
                'client.ingredientExclusions',
                'client.dishExclusions',
                'replacements.replacementProduct',
                'replacements.replacementDish',
                'projectData',
            ])
            ->get();

        if ($orders->isEmpty()) {
            return;
        }

        foreach ($orders as $order) {
            $this->orderPlans[$order->id] = $this->calculateOrderPlan($order, $menu);
        }

        $sortedMenuItems = $menu->menuItems->sortBy(fn ($i) => $i->mealType?->sort_order ?? 99);

        foreach ($sortedMenuItems as $mItem) {
            $dish = $mItem->dish;
            if (!$dish) continue;

            $tableData = [
                'meal' => $mItem->mealType->name ?? 'Інше',
                'dish_name' => $dish->name,
                'columns' => [],
                'rows' => [],
                'individual_notes' => [], 
            ];

            foreach ($orders as $order) {
                $plan = $this->orderPlans[$order->id] ?? null;
                if (!$plan) continue;

                $plannedWeight = $this->plannedDishWeight(
                    $plan['items'] ?? [],
                    (int)$dish->id,
                    (int)$mItem->meal_type_id
                );

                if ($plannedWeight === null) continue;

                // ЗБИРАЄМО НОТАТКИ (Заміни страв/інгредієнтів)
                $notes = $this->collectOrderNotes($order, $dish);
                if (!empty($notes)) {
                    $tableData['individual_notes'] = array_merge($tableData['individual_notes'], $notes);
                }

                $baseW = (float)($dish->base_weight_g ?? 0);
                $dishScale = $baseW > 0 ? ((float)$plannedWeight / $baseW) : 0.0;

                $colKey   = (string)(int)($order->calories ?? 0);
                $projSlug = $order->project ?? 'none';
                $projName = $order->projectData?->name ?? ucfirst($projSlug);

                if (!isset($tableData['columns'][$colKey])) {
                    $tableData['columns'][$colKey] = [
                        'count'     => 0,
                        'sum_scale' => 0.0,
                        'projects'  => [],
                    ];
                }

                $tableData['columns'][$colKey]['count']++;
                $tableData['columns'][$colKey]['sum_scale'] += $dishScale;

                if (!isset($tableData['columns'][$colKey]['projects'][$projSlug])) {
                    $tableData['columns'][$colKey]['projects'][$projSlug] = ['name' => $projName, 'count' => 0];
                }
                $tableData['columns'][$colKey]['projects'][$projSlug]['count']++;
            }

            if (empty($tableData['columns'])) {
                if (!empty($tableData['individual_notes'])) {
                    $this->report[] = $tableData;
                }
                continue;
            }

            ksort($tableData['columns']);

            foreach ($dish->dishIngredients as $di) {
                $name = $di->ingredient
                    ? $di->ingredient->name
                    : ($di->childDish ? "[НФ] " . $di->childDish->name : '???');

                $netWeight = (float)($di->net_weight_g ?? 0);
                $cells = [];
                foreach ($tableData['columns'] as $key => $col) {
                    $count    = (int)($col['count'] ?? 1);
                    $sumScale = (float)($col['sum_scale'] ?? 0.0);

                    $onePortionScale = $count > 0 ? ($sumScale / $count) : 0;

                    $cells[$key] = round($netWeight * $onePortionScale);
                }

                $tableData['rows'][] = [
                    'original_name' => $name,
                    'cells' => $cells,
                ];
            }

            $this->report[] = $tableData;
        }
    }

    // =========================================================
    // 🔧 ДОПОМІЖНІ МЕТОДИ (ЛОГІКА ЗАМІН)
    // =========================================================

    private function collectOrderNotes(Order $order, $dish): array
    {
        $notes = [];
        if (!$order->client) return $notes;

        $clientInfo = $order->client->name . ' (' . (int)($order->calories ?? 0) . ' ккал)';

        // 1. Коментарі
        $comment = trim($order->client->production_comment ?? '');
        if (!empty($comment)) {
            $notes[] = "{$clientInfo}: {$comment}";
        }

        // 2. Виключення цілої страви
        if ($order->client->dishExclusions->contains('id', $dish->id)) {
            $rep = $order->replacements->where('dish_id', $dish->id)->whereNull('original_product_id')->first();
            if ($rep && $rep->replacementDish) {
                $notes[] = "{$clientInfo}: Страву повністю замінено на «{$rep->replacementDish->name}»";
            } else {
                $notes[] = "{$clientInfo}: Страву повністю ВИКЛЮЧЕНО";
            }
            // Якщо страва виключена, не перевіряємо інгредієнти
            return $notes;
        }

        // 3. Виключення інгредієнтів (рекурсивно)
        $this->checkIngredientsForNotes($dish, $order, $dish->id, $clientInfo, $notes);

        return $notes;
    }

    private function checkIngredientsForNotes($dish, $order, $rootDishId, $clientInfo, array &$notes): void
    {
        if (!$dish || !$dish->dishIngredients) return;

        foreach ($dish->dishIngredients as $di) {
            // Звичайний продукт
            if ($di->ingredient_id && $order->client->ingredientExclusions->contains('id', $di->ingredient_id)) {
                $rep = $order->replacements->where('dish_id', $rootDishId)->where('original_product_id', $di->ingredient_id)->first();
                if ($rep && $rep->replacementProduct) {
                    $notes[] = "{$clientInfo}: «{$di->ingredient->name}» замінено на «{$rep->replacementProduct->name}»";
                } else {
                    $notes[] = "{$clientInfo}: Без «{$di->ingredient->name}»";
                }
            }
            // Якщо це ПФ - йдемо вглиб
            if ($di->child_dish_id && $di->childDish) {
                $this->checkIngredientsForNotes($di->childDish, $order, $rootDishId, $clientInfo, $notes);
            }
        }
    }

    // =========================================================
    // 🔧 ДОПОМІЖНІ МЕТОДИ (РОЗРАХУНКИ)
    // =========================================================

    private function plannedDishWeight(array $items, int $dishId, int $mealTypeId): ?int
    {
        foreach ($items as $it) {
            if ((int)($it['dish_id'] ?? 0) === $dishId && (int)($it['meal_type_id'] ?? 0) === $mealTypeId) {
                return (int)($it['weight'] ?? 0);
            }
        }
        return null;
    }

    private function calculateOrderPlan(Order $order, DailyMenu $menu): array
    {
        $targetKcal = (float)($order->calories ?? 0);
        if ($targetKcal <= 0) return ['items' => []];

        $clientMealTypeIds = $order->client?->mealTypes?->pluck('id')->toArray() ?? [];

        $availableItems = $menu->menuItems
            ->filter(fn ($item) => $item->dish && in_array($item->meal_type_id, $clientMealTypeIds, true))
            ->sortBy(fn ($item) => $item->mealType?->sort_order ?? 99)
            ->values();

        if ($availableItems->isEmpty()) return ['items' => []];

        $allowedSortOrders = \App\Models\MealPlan::getAllowedSortOrders((int)$targetKcal);
        $selected = $availableItems->filter(
            fn ($item) => in_array($item->mealType?->sort_order, $allowedSortOrders)
        )->values();
        if ($selected->isEmpty()) return ['items' => []];

        $byMeal = $selected->groupBy('meal_type_id');

        // Нормалізація відсотків до 100% для вибраних страв
        $rawPct = [];
        foreach ($byMeal as $mealTypeId => $items) {
            $fi = $items->first();
            $rawPct[$mealTypeId] = $fi->custom_energy_percent !== null
                ? (float) $fi->custom_energy_percent
                : (float) ($fi->mealType?->energy_percent ?? 0);
        }
        $totalPct = array_sum($rawPct);
        $normFactor = ($totalPct > 0.5 && abs($totalPct - 100) > 0.5) ? (100.0 / $totalPct) : 1.0;

        $itemsOut = [];

        foreach ($byMeal as $mealTypeId => $items) {
            $firstItem = $items->first();

            $p = ($rawPct[$mealTypeId] ?? 0) * $normFactor;

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

                $itemsOut[] = [
                    'dish_id' => (int)$dish->id,
                    'meal_type_id' => (int)$mealTypeId,
                    'weight' => $weight,
                ];
            }
        }

        return ['items' => $itemsOut];
    }


    private function dishKcalPer100g($dish): float
    {
        $baseW = (float)($dish->base_weight_g ?? 0);
        $totalKcal = (float)($dish->total_kcal ?? 0);

        if ($baseW <= 0 || $totalKcal <= 0) return 0.0;

        return ($totalKcal / $baseW) * 100.0;
    }
}