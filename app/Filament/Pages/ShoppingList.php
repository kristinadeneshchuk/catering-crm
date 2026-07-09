<?php

namespace App\Filament\Pages;

use Filament\Pages\Page;
use App\Models\DailyMenu;
use App\Models\Order;
use App\Models\Ingredient;
use App\Models\Setting;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Carbon\Carbon;
use App\Traits\CalculatesOrderPlan;

class ShoppingList extends Page implements HasForms
{
    use InteractsWithForms, CalculatesOrderPlan;

    protected static ?string $navigationIcon = 'heroicon-o-shopping-cart';
    protected static ?string $navigationLabel = 'Список покупок';
    protected static ?string $title = 'Список покупок';
    protected static string $view = 'filament.pages.shopping-list';

    public ?array $data = [];
    public array $shoppingList = [];
    public array $missingPlans = [];
    public string $selectedDate = '';

    public static function canAccess(): bool
    {
        return in_array(auth()->user()->role, ['admin', 'manager', 'cook']);
    }

    public function mount(): void
    {
        $this->form->fill(['date' => now()->addDay()->format('Y-m-d')]);
        $this->calculate();
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                DatePicker::make('date')
                    ->label('Дата закупівлі (для якого дня готуємо)')
                    ->displayFormat('d.m.Y')
                    ->required()
                    ->live()
                    ->afterStateUpdated(fn () => $this->calculate()),
            ])
            ->statePath('data');
    }

    public function calculate(): void
    {
        $date = $this->data['date'] ?? now()->addDay()->format('Y-m-d');
        $this->selectedDate = $date;

        // Замовлення через orderDays
        $orders = Order::whereHas('orderDays', fn ($q) => $q->where('date', $date))
            ->whereIn('status', ['new', 'active'])
            ->with([
                'client.mealTypes',
                'client.ingredientExclusions',
                'client.dishExclusions',
                'client.replacementBundles.items.originalIngredient',
                'ingredientExclusions',
                'menuPlan',
                'replacements.replacementProduct',
                'replacements.replacementDish.dishIngredients.ingredient',
            ])
            ->get();

        if ($orders->isEmpty()) {
            $this->shoppingList = [];
            $this->missingPlans = [];
            return;
        }

        $this->missingPlans = [];

        // Збираємо брутто глобально по всіх планах (купуємо в магазині один раз)
        $bruttoByIngredient = [];

        // Групуємо замовлення по планах меню
        $ordersByPlan = $orders->groupBy(fn ($o) => $o->effectiveMenuPlan()?->id ?? 0);

        foreach ($ordersByPlan as $planId => $planOrders) {
            $plan = $planOrders->first()->effectiveMenuPlan();
            if (!$plan) continue;

            $globalDay = $plan->globalDayFor($date);
            $menu = DailyMenu::where('menu_plan_id', $plan->id)
                ->where('day_number', $globalDay)
                ->with([
                    'menuItems.dish.dishIngredients.ingredient',
                    'menuItems.dish.dishIngredients.childDish.dishIngredients.ingredient',
                    'menuItems.mealType',
                ])
                ->first();
            if (!$menu) {
                $this->missingPlans[] = [
                    'plan'         => $plan,
                    'day_number'   => $globalDay,
                    'orders_count' => $planOrders->count(),
                    'client_names' => $planOrders->map(fn ($o) => $o->client?->name)->filter()->unique()->take(5)->values()->all(),
                ];
                continue;
            }

            $orderPlans = [];
            foreach ($planOrders as $order) {
                $orderPlans[$order->id] = $this->calculateOrderPlan($order, $menu, $date);
            }

            foreach ($menu->menuItems->sortBy(fn ($i) => $i->mealType?->sort_order ?? 99) as $item) {
                if (!$item->dish) continue;

                $dish = $item->dish;

                foreach ($planOrders as $order) {
                    if ($order->menu_type === 'individual') continue;

                    $orderPlan = $orderPlans[$order->id] ?? null;
                    if (!$orderPlan) continue;

                    $plannedWeight = collect($orderPlan['items'])->first(
                        fn ($it) => (int)$it['dish_id'] === (int)$dish->id
                            && (int)$it['meal_type_id'] === (int)$item->meal_type_id
                    )['weight'] ?? null;

                    if ($plannedWeight === null) continue;

                    $baseW     = (float)($dish->base_weight_g ?? 0);
                    $dishScale = $baseW > 0 ? ($plannedWeight / $baseW) : 0.0;

                    $dishReplacement = $order->replacements
                        ->where('dish_id', $dish->id)->whereNull('original_product_id')->first();

                    $activeDish = ($dishReplacement?->replacementDish) ?? $dish;

                    if ($order->client->dishExclusions->contains('id', $dish->id) && !$dishReplacement) {
                        continue;
                    }

                    $this->collectBrutto(
                        $activeDish,
                        $dishScale,
                        1.0,
                        $order,
                        (int)$dish->id,
                        $bruttoByIngredient
                    );
                }
            }

            // === ІНДИВІДУАЛЬНІ КЛІЄНТИ цього плану ===
            foreach ($planOrders as $order) {
                if ($order->menu_type !== 'individual') continue;

                $orderPlan = $orderPlans[$order->id] ?? null;
                if (!$orderPlan || empty($orderPlan['items'])) continue;

                foreach ($orderPlan['items'] as $item) {
                    $dish = \App\Models\Dish::with(
                        'dishIngredients.ingredient',
                        'dishIngredients.childDish.dishIngredients.ingredient'
                    )->find($item['dish_id']);
                    if (!$dish) continue;

                    $weight = (int)$item['weight'];
                    $baseW  = (float)($dish->base_weight_g ?? 0);
                    $scale  = $baseW > 0 ? $weight / $baseW : 0.0;

                    $this->collectBrutto($dish, $scale, 1.0, null, (int)$dish->id, $bruttoByIngredient);
                }
            }
        }

        // Порівнюємо з залишками на складі
        $finalList = [];
        foreach ($bruttoByIngredient as $id => $info) {
            $dbIng = Ingredient::find($id);
            if (!$dbIng) continue;

            $stock    = (float)($dbIng->stock ?? 0);
            $unit     = $info['unit'];
            $bruttoG  = $info['brutto_g'];

            // ✅ Правильна конвертація: brutto завжди в грамах, stock в одиницях виміру інгредієнта
            $bruttoInUnit = in_array($unit, ['кг', 'л', 'kg', 'l'])
                ? $bruttoG / 1000.0
                : $bruttoG;

            $toBuy = max(0.0, $bruttoInUnit - $stock);

            $finalList[] = [
                'id'      => $id,
                'name'    => $info['name'],
                'need'    => $bruttoInUnit,
                'stock'   => $stock,
                'to_buy'  => $toBuy,
                'unit'    => $unit,
                'enough'  => $toBuy <= 0,
            ];
        }

        usort($finalList, fn ($a, $b) => strcmp($a['name'], $b['name']));
        $this->shoppingList = $finalList;
    }

    // =============================================
    // Калорійне масштабування (як в ProductionReport)
    // =============================================
    // Рекурсивний збір брутто по інгредієнтах
    private function collectBrutto($dish, float $scale, float $subRatio, $order, int $rootDishId, array &$acc): void
    {
        if (!$dish || !$dish->dishIngredients) return;

        foreach ($dish->dishIngredients as $di) {
            $k    = $scale * $subRatio;
            $type = mb_strtolower(trim((string)($di->type ?? '')));
            $netG = (float)($di->net_weight_g ?? 0) * $k;

            $isProduct = in_array($type, ['product', 'продукт'], true);
            $isPf      = in_array($type, ['pf', 'напівфабрикат', 'п/ф', 'н/ф'], true);

            if ($isProduct && $di->ingredient) {
                $ing = $di->ingredient;

                // Заміна інгредієнта
                $rep = $order?->replacements
                    ->where('dish_id', $rootDishId)
                    ->where('original_product_id', $ing->id)
                    ->first();
                if ($rep?->replacementProduct) {
                    $ing = $rep->replacementProduct;
                }

                // Пропускаємо виключений (без заміни) — але якщо є заміна, беремо її
                $isExcluded = $order?->effectiveExcludedIngredients()->contains('id', $di->ingredient->id) ?? false;
                if ($isExcluded && !$rep?->replacementProduct) continue;

                $yield   = (float)($ing->yield_percent ?: 100);
                $bruttoG = ($netG * 100) / max($yield, 1);

                if (!isset($acc[$ing->id])) {
                    $acc[$ing->id] = ['name' => $ing->name, 'brutto_g' => 0.0, 'unit' => $ing->unit ?? 'г'];
                }
                $acc[$ing->id]['brutto_g'] += $bruttoG;
            }

            if ($isPf && $di->childDish) {
                $pfTotals = $di->childDish->calculated_totals;
                $pfOutput = (float)($pfTotals['output_weight'] ?? 0);
                if ($pfOutput <= 0) continue;

                $pfRatio = ((float)($di->net_weight_g ?? 0)) / $pfOutput;
                $this->collectBrutto($di->childDish, $scale, $pfRatio * $subRatio, $order, $rootDishId, $acc);
            }
        }
    }

    public function getPrintUrl(): string
    {
        return route('print.shopping-list', ['date' => $this->selectedDate]);
    }
}
