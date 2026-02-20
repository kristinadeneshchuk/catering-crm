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
            Action::make('print_stickers')
                ->label('1. Стікери')
                ->icon('heroicon-o-tag')
                ->color('gray')
                // 🔥 БЕРЕМО СВІЖУ ДАТУ ЗІ СТЕЙТУ
                ->url(fn () => route('print.stickers', ['date' => $this->data['date'] ?? now()->format('Y-m-d')]))
                ->openUrlInNewTab(),

            Action::make('print_mini_manifest')
                ->label('2. На пакет')
                ->icon('heroicon-o-document-text')
                ->color('info')
                // 🔥 БЕРЕМО СВІЖУ ДАТУ ЗІ СТЕЙТУ
                ->url(fn () => route('print.mini-manifest', ['date' => $this->data['date'] ?? now()->format('Y-m-d')]))
                ->openUrlInNewTab(),

            Action::make('print_manifest')
                ->label('3. В пакет (з меню)')
                ->icon('heroicon-o-shopping-bag')
                ->color('warning')
                // 🔥 БЕРЕМО СВІЖУ ДАТУ ЗІ СТЕЙТУ
                ->url(fn () => route('print.manifest', ['date' => $this->data['date'] ?? now()->format('Y-m-d')]))
                ->openUrlInNewTab(),

            Action::make('download_logistics')
                ->label('Завантажити логістику (Excel)')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('success')
                // 🔥 БЕРЕМО СВІЖУ ДАТУ ЗІ СТЕЙТУ
                ->url(fn () => route('print.logistics', ['date' => $this->data['date'] ?? now()->format('Y-m-d')])),
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
                                    📦 Фасування на <strong class='text-primary-400'>завтра (" . $targetDateObj->format('d.m.Y') . ")</strong>.
                                    <br> Це буде <strong class='text-primary-400'>" . $dayNum . "-й день</strong> циклу меню.
                                </div>"
                            );
                        }),
                        
                    \Filament\Forms\Components\Actions::make([
                        \Filament\Forms\Components\Actions\Action::make('print_view')
                            ->label('🖨 Відкрити версію для друку')
                            ->color('warning')
                            ->url(fn () => route('print.packaging-list', ['date' => $this->data['date'] ?? now()->format('Y-m-d')]))
                            ->openUrlInNewTab()
                    ])->alignRight(),
                ])
            ])
        ])->statePath('data');
    }

    // =========================================================
    // ✅ ОСНОВНИЙ РОЗРАХУНОК ФАСУВАННЯ (ПРАВИЛЬНИЙ)
    // =========================================================
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
            $this->debugMessage = "⚠️ Меню на {$targetDateObj->format('d.m.Y')} (день циклу {$globalDay}) не знайдено";
            return;
        }

        $orders = Order::whereIn('status', ['new', 'active'])
            ->whereHas('orderDays', fn ($q) => $q->where('date', $targetDate))
            ->with(['client.mealTypes'])
            ->get();

        if ($orders->isEmpty()) {
            return;
        }

        // ✅ 1) Строим ПРАВИЛЬНЫЙ план (как в ProductionReport / PrintController)
        foreach ($orders as $order) {
            $this->orderPlans[$order->id] = $this->calculateOrderPlan($order, $menu);
        }

        $sortedMenuItems = $menu->menuItems->sortBy(fn ($i) => $i->mealType?->sort_order ?? 99);

        // ✅ 2) Фасування по кожній страві меню
        foreach ($sortedMenuItems as $mItem) {
            $dish = $mItem->dish;
            if (!$dish) continue;

            $tableData = [
                'meal' => $mItem->mealType->name ?? 'Інше',
                'dish_name' => $dish->name,

                // columns: [kcal => ['count'=>int, 'sum_scale'=>float]]
                'columns' => [],
                'rows' => [],
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

                $baseW = (float)($dish->base_weight_g ?? 0);
                $dishScale = $baseW > 0 ? ((float)$plannedWeight / $baseW) : 0.0;

                $colKey = (string)(int)($order->calories ?? 0);

                if (!isset($tableData['columns'][$colKey])) {
                    $tableData['columns'][$colKey] = [
                        'count' => 0,
                        'sum_scale' => 0.0,
                    ];
                }

                $tableData['columns'][$colKey]['count']++;
                $tableData['columns'][$colKey]['sum_scale'] += $dishScale;
            }

            if (empty($tableData['columns'])) continue;

            ksort($tableData['columns']);

            // ✅ 3) Масштабуємо інгредієнти (правильно: net_weight_g * sum_scale)
            foreach ($dish->dishIngredients as $di) {
                $name = $di->ingredient
                    ? $di->ingredient->name
                    : ($di->childDish ? "📦 " . $di->childDish->name : '???');

                $cells = [];
                foreach ($tableData['columns'] as $key => $col) {
                    $sumScale = (float)($col['sum_scale'] ?? 0.0);
                    $cells[$key] = [
                        'val' => round(((float)($di->net_weight_g ?? 0)) * $sumScale),
                    ];
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
    // 🔧 ДОПОМІЖНІ МЕТОДИ
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

    /**
     * ✅ ТА Ж ЛОГІКА, ЩО В ProductionReport/PrintController:
     * - отбираем mealTypes клиента
     * - выбираем первые N блюд по sort_order
     * - распределяем ккал по energy_percent (нормализуем по использованным mealTypes)
     * - считаем вес блюда по kcalPerDish и kcalPer100
     */
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

        $expectedDishes = $this->expectedDishCount((int)$targetKcal);

        $selected = $availableItems->take($expectedDishes);
        if ($selected->isEmpty()) return ['items' => []];

        $byMeal = $selected->groupBy('meal_type_id');

        // нормализация процентов
        $percentSum = 0.0;
        foreach ($byMeal as $mealTypeId => $items) {
            $percentSum += (float)($items->first()->mealType?->energy_percent ?? 0);
        }
        if ($percentSum <= 0) $percentSum = 100.0;

        $itemsOut = [];

        foreach ($byMeal as $mealTypeId => $items) {
            $mealType = $items->first()->mealType;
            $p = (float)($mealType?->energy_percent ?? 0);

            $mealKcal = ($p > 0)
                ? $targetKcal * ($p / $percentSum)
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

    private function expectedDishCount(int $kcal): int
    {
        if ($kcal < 1200) return 3;
        if ($kcal < 1500) return 4;
        return 5;
    }

    private function dishKcalPer100g($dish): float
    {
        $baseW = (float)($dish->base_weight_g ?? 0);
        $totalKcal = (float)($dish->total_kcal ?? 0);

        if ($baseW <= 0 || $totalKcal <= 0) return 0.0;

        return ($totalKcal / $baseW) * 100.0;
    }
}