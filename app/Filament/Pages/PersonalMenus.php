<?php

namespace App\Filament\Pages;

use App\Traits\RestrictCookAccess;
use Filament\Pages\Page;
use App\Models\Order;
use App\Models\Dish;
use App\Models\DailyMenu;
use App\Models\OrderDayDish;
use App\Models\Setting;
use Carbon\Carbon;

class PersonalMenus extends Page
{
    use RestrictCookAccess;
    use \App\Traits\CalculatesOrderPlan;

    protected static ?string $navigationIcon  = 'heroicon-o-user-circle';
    protected static ?string $navigationLabel = 'Персональні меню';
    protected static ?string $title           = 'Персональні меню';
    protected static string  $view            = 'filament.pages.personal-menus';
    protected static ?int    $navigationSort  = 5;

    public string $date   = '';
    public array  $cards  = [];

    // Інгредієнти циклічного меню на цю дату (для підказок "є на кухні")
    public array $kitchenIngredientIds = [];

    // --- Стан модального вікна ---
    public ?int   $modalOrderId       = null;
    public ?int   $modalMealTypeId    = null;
    public ?int   $modalCurrentDishId = null;
    public string $modalMealLabel     = '';
    public string $modalClientName    = '';
    // [['id', 'name', 'kcal', 'is_kitchen'], ...]
    public array  $modalDishes        = [];

    public function mount(): void
    {
        $this->date = now()->format('Y-m-d');
        $this->loadData();
    }

    public function updatedDate(): void { $this->loadData(); }
    public function prevDay(): void
    {
        $this->date = Carbon::parse($this->date)->subDay()->format('Y-m-d');
        $this->loadData();
    }
    public function nextDay(): void
    {
        $this->date = Carbon::parse($this->date)->addDay()->format('Y-m-d');
        $this->loadData();
    }

    // -----------------------------------------------------------------------
    // Модальне вікно
    // -----------------------------------------------------------------------

    public function openDishModal(int $orderId, int $mealTypeId): void
    {
        $this->modalOrderId    = $orderId;
        $this->modalMealTypeId = $mealTypeId;

        foreach ($this->cards as $card) {
            if ($card['order_id'] === $orderId) {
                $this->modalClientName = $card['client'];
                foreach ($card['meals'] as $meal) {
                    if ($meal['meal_type_id'] === $mealTypeId) {
                        $this->modalCurrentDishId = $meal['current_dish_id'];
                        $this->modalMealLabel     = $meal['meal_type_name'];
                        break 2;
                    }
                }
            }
        }
        $excluded = Order::find($orderId)?->effectiveExcludedIngredientIds() ?? [];
        $availableIds = $excluded ? $this->getAvailableDishIds($excluded) : [];

        $query = Dish::with('dishIngredients.ingredient')
            ->where('name', 'not like', '%н/ф%')
            ->orderBy('name');
        if (!empty($availableIds)) {
            $query->whereIn('id', $availableIds);
        }

        $kitchenIds = $this->kitchenIngredientIds;

        $dishes = $query->get()->map(function ($d) use ($kitchenIds) {
            // Тільки значущі інгредієнти страви (≥ 20г)
            $significant = $d->dishIngredients
                ->filter(fn($di) => (float)$di->net_weight_g >= 20 && $di->ingredient_id);

            $significantIds = $significant->pluck('ingredient_id')->toArray();

            // Які саме збігаються з кухнею
            $matchingIngredients = $significant
                ->filter(fn($di) => !empty($kitchenIds) && in_array($di->ingredient_id, $kitchenIds))
                ->map(fn($di) => $di->ingredient?->name)
                ->filter()
                ->values()
                ->toArray();

            $matches   = count($matchingIngredients);
            $isKitchen = $matches >= 2;

            return [
                'id'                  => $d->id,
                'name'                => $d->name,
                'is_kitchen'          => $isKitchen,
                'matches'             => $matches,
                'matching_ingredients'=> $matchingIngredients,
            ];
        });

        // Кухонні страви — спочатку, решта — за алфавітом
        $this->modalDishes = $dishes
            ->sortBy(fn($d) => [$d['is_kitchen'] ? 0 : 1, $d['name']])
            ->values()
            ->toArray();
    }

    public function closeDishModal(): void
    {
        $this->modalOrderId    = null;
        $this->modalMealTypeId = null;
        $this->modalDishes     = [];
    }

    public function pickDish(?int $dishId): void
    {
        if ($this->modalOrderId && $this->modalMealTypeId) {
            $this->setDish($this->modalOrderId, $this->modalMealTypeId, $dishId);
        }
        $this->closeDishModal();
    }

    // -----------------------------------------------------------------------
    // Збереження страви
    // -----------------------------------------------------------------------

    /**
     * Підбір меню через ШІ за брифом клієнта.
     *
     * Модель обирає лише страви, менеджер після цього править руками — те, що
     * вона вигадала або не змогла підібрати, повертається списком.
     */
    public function generateMenu(int $orderId): void
    {
        $order = Order::with('client.mealTypes')->find($orderId);

        if (! $order) {
            return;
        }

        try {
            $result = app(\App\Services\Menu\IndividualMenuGenerator::class)
                ->generate($order, $this->date);
        } catch (\Illuminate\Validation\ValidationException $e) {
            \Filament\Notifications\Notification::make()
                ->title('Меню не складено')
                ->body(collect($e->errors())->flatten()->first())
                ->danger()
                ->persistent()
                ->send();

            return;
        } catch (\Throwable $e) {
            \Filament\Notifications\Notification::make()
                ->title('Помилка підбору')
                ->body(mb_substr($e->getMessage(), 0, 300))
                ->danger()
                ->persistent()
                ->send();

            return;
        }

        $this->loadData();

        \Filament\Notifications\Notification::make()
            ->title("Підібрано страв: {$result['assigned']}")
            ->body($result['skipped']
                ? 'Не вдалося підібрати: '.implode(', ', $result['skipped'])
                : implode("\n", $result['meals']))
            ->{$result['skipped'] ? 'warning' : 'success'}()
            ->send();
    }

    /** Обіди «частина дня»: обрати / прибрати страву меню дня. */
    public function togglePick(int $orderId, int $mealTypeId, int $dishId): void
    {
        $exists = OrderDayDish::where('order_id', $orderId)->where('date', $this->date)
            ->where('meal_type_id', $mealTypeId)->where('dish_id', $dishId)->exists();
        $this->setDish($orderId, $mealTypeId, $exists ? null : $dishId);
    }

    public function setDish(int $orderId, int $mealTypeId, ?int $dishId): void
    {
        if (!$dishId) {
            OrderDayDish::where('order_id', $orderId)
                ->where('date', $this->date)
                ->where('meal_type_id', $mealTypeId)
                ->delete();
        } else {
            OrderDayDish::updateOrCreate(
                ['order_id' => $orderId, 'date' => $this->date, 'meal_type_id' => $mealTypeId],
                ['dish_id'  => $dishId, 'weight_grams' => null]
            );
        }
        $this->loadData();
    }

    // -----------------------------------------------------------------------
    // Завантаження даних
    // -----------------------------------------------------------------------

    public function loadData(): void
    {
        $this->kitchenIngredientIds = $this->getKitchenIngredientIds($this->date);
        \App\Models\Ingredient::preloadAveragePrices();

        // Як кухня: на паузі й завершені на сьогодні й далі не годуємо.
        $orders = Order::where(fn ($q) => $q->where('menu_type', 'individual')
                // Корпоративні обіди «частина дня» — страви теж обираємо тут.
                ->when(\App\Support\SchemaReady::hasColumn('orders', 'partial_day'), fn ($w) => $w->orWhere('partial_day', true)))
            ->feedingOn($this->date)
            ->with([
                'client.mealTypes',
                'client.ingredientExclusions',
                'client.replacementBundles.items.originalIngredient',
                'ingredientExclusions',
                'projectData',
                'personalDishes' => fn($q) => $q->where('date', $this->date)->with([
                    'mealType',
                    'dish.dishIngredients.ingredient',
                    'dish.dishIngredients.childDish.dishIngredients.ingredient',
                ]),
            ])
            ->get();

        $multiplier = \App\Support\DailyWeightMultiplier::for($this->date);
        $this->cards = [];

        foreach ($orders as $order) {
            $client  = $order->client;
            $project = $order->projectData;

            // Порція й КБЖВ кожної страви — так само, як рахує виробництво.
            // Для обідів «частина дня» порція залежить від % прийомів у меню дня.
            $menu = null;
            if ($order->partial_day && ($menuPlan = $order->effectiveMenuPlan())) {
                $menu = \App\Models\DailyMenu::where('menu_plan_id', $menuPlan->id)->where('day_number', $menuPlan->globalDayFor($this->date))
                    ->with(['menuItems.dish', 'menuItems.mealType'])->first();
            }
            $plan  = $this->buildPlanFromPersonal($order->personalDishes, $order, $multiplier, $menu);
            $items = collect($plan['items'])->keyBy('meal_type_id');
            $names = $order->personalDishes->mapWithKeys(fn($pd) => [$pd->meal_type_id => $pd->dish?->name]);

            // Виключення могли з'явитися вже після того, як страву призначили
            // (анкету правлять посеред періоду) — тоді кухня має це побачити.
            $excludedIngIds = $order->effectiveExcludedIngredientIds();
            $assignedIds    = $order->personalDishes->pluck('dish_id')->filter()->unique()->all();
            $conflictIds    = ($excludedIngIds && $assignedIds)
                ? Dish::whereIn('id', $assignedIds)
                    ->whereHas('dishIngredients', fn($q) => $q->whereIn('ingredient_id', $excludedIngIds))
                    ->pluck('id')->all()
                : [];

            $meals = [];
            // Обіди «частина дня»: рядки — меню цього дня; обираємо страви з нього,
            // щоб кухня готувала й фасувала їх разом із циклічними.
            if ($order->partial_day) {
                foreach (($menu?->menuItems ?? collect())->sortBy(fn ($mi) => $mi->mealType?->sort_order ?? 99) as $mi) {
                    if (!$mi->dish) continue;
                    $item = $items[$mi->meal_type_id] ?? null;
                    $picked = $item && (int) $item['dish_id'] === (int) $mi->dish_id;
                    $meals[] = [
                        'meal_type_id'    => $mi->meal_type_id,
                        'meal_type_name'  => $mi->mealType?->name ?? '—',
                        'menu_dish_id'    => (int) $mi->dish_id,
                        'menu_dish_name'  => $mi->dish->name,
                        'current_dish_id' => $picked ? (int) $mi->dish_id : null,
                        'dish_name'       => $picked ? $mi->dish->name : null,
                        'weight'          => $picked ? $item['weight'] : null,
                        'macros'          => $picked ? $item['macros'] : null,
                        'conflict'        => $picked && in_array($item['dish_id'], $conflictIds, true),
                    ];
                }
            }
            foreach ($order->partial_day ? [] : $client->mealTypes->sortBy('sort_order') as $mt) {
                $item = $items[$mt->id] ?? null;
                $meals[] = [
                    'meal_type_id'    => $mt->id,
                    'meal_type_name'  => $mt->name,
                    'current_dish_id' => $item['dish_id'] ?? null,
                    'dish_name'       => $names[$mt->id] ?? null,
                    'weight'          => $item['weight'] ?? null,
                    'macros'          => $item['macros'] ?? null,
                    'conflict'        => $item && in_array($item['dish_id'], $conflictIds, true),
                ];
            }

            $isEvening = str_contains((string)$order->schedule_type, 'evening');

            $this->cards[] = [
                'order_id'   => $order->id,
                'client_id'  => $client->id,
                'client'     => $client->name,
                'calories'   => (int)$order->calories,
                'kits'       => $order->kits(),
                'partial'    => (bool) $order->partial_day,
                'totals'     => array_map(fn($v) => (int) round($v), $plan['totals']),
                'project'    => $project?->name ?? ucfirst($order->project ?? ''),
                'color'      => $project?->color ?? 'gray',
                'is_evening' => $isEvening,
                'slot'       => $isEvening ? 'Вечір' : 'Ранок',
                'meals'      => $meals,
                'filled'     => count(array_filter($meals, fn($m) => $m['current_dish_id'])),
                'total'      => count($meals),
                'conflicts'  => count(array_filter($meals, fn($m) => $m['conflict'])),
            ];
        }
    }

    // -----------------------------------------------------------------------
    // Хелпери
    // -----------------------------------------------------------------------

    /**
     * Значущі інгредієнти циклічного меню на цю дату (≥ 20г).
     * Виключаємо сіль, олію, спеції — лише основна сировина.
     */
    private function getKitchenIngredientIds(string $date): array
    {
        // TODO (multi-plan): зараз бере інгредієнти з дефолтного плану.
        $defaultPlan = \App\Models\MenuPlan::default();
        if (!$defaultPlan) return [];

        $globalDay = $defaultPlan->globalDayFor($date);
        $menu = DailyMenu::where('menu_plan_id', $defaultPlan->id)
            ->where('day_number', $globalDay)
            ->with('menuItems.dish.dishIngredients')
            ->first();

        if (!$menu) return [];

        $ids = [];
        foreach ($menu->menuItems as $mi) {
            if (!$mi->dish) continue;
            foreach ($mi->dish->dishIngredients as $di) {
                // Тільки значущі інгредієнти — мінімум 20г
                if ($di->ingredient_id && (float)$di->net_weight_g >= 20) {
                    $ids[] = $di->ingredient_id;
                }
            }
        }

        return array_unique($ids);
    }

    private function getAvailableDishIds(array $excludedIngIds): array
    {
        if (empty($excludedIngIds)) {
            return [];
        }

        return Dish::whereDoesntHave('dishIngredients', fn($q) =>
            $q->whereIn('ingredient_id', $excludedIngIds)
        )->pluck('id')->toArray();
    }
}
