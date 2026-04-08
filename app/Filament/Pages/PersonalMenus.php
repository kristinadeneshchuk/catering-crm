<?php

namespace App\Filament\Pages;

use Filament\Pages\Page;
use App\Models\Order;
use App\Models\Dish;
use App\Models\DailyMenu;
use App\Models\OrderDayDish;
use App\Models\Setting;
use Carbon\Carbon;

class PersonalMenus extends Page
{
    protected static ?string $navigationIcon  = 'heroicon-o-user-circle';
    protected static ?string $navigationLabel = 'Персональні меню';
    protected static ?string $title           = 'Персональні меню';
    protected static string  $view            = 'filament.pages.personal-menus';
    protected static ?int    $navigationSort  = 5;

    public string $date   = '';
    public array  $cards  = [];
    public array  $allDishes = [];

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
        $this->loadAllDishes();
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

        $availableIds = [];
        foreach ($this->cards as $card) {
            if ($card['order_id'] === $orderId) {
                $this->modalClientName = $card['client'];
                foreach ($card['meals'] as $meal) {
                    if ($meal['meal_type_id'] === $mealTypeId) {
                        $this->modalCurrentDishId = $meal['current_dish_id'];
                        $this->modalMealLabel     = $meal['meal_type_name'];
                        $availableIds             = $meal['available_ids'];
                        break 2;
                    }
                }
            }
        }

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
                'kcal'                => $d->total_kcal ? (int) $d->total_kcal : null,
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

    private function loadAllDishes(): void
    {
        $this->allDishes = Dish::orderBy('name')
            ->get()
            ->mapWithKeys(fn($d) => [
                $d->id => $d->name . ($d->total_kcal ? ' (' . (int)$d->total_kcal . ' ккал)' : ''),
            ])
            ->toArray();
    }

    public function loadData(): void
    {
        $this->kitchenIngredientIds = $this->getKitchenIngredientIds($this->date);

        $orders = Order::where('menu_type', 'individual')
            ->whereHas('orderDays', fn($q) => $q->where('date', $this->date))
            ->with([
                'client.mealTypes',
                'client.ingredientExclusions',
                'projectData',
                'orderDays'      => fn($q) => $q->where('date', $this->date),
                'personalDishes' => fn($q) => $q->where('date', $this->date),
            ])
            ->get();

        $this->cards = [];

        foreach ($orders as $order) {
            $client  = $order->client;
            $project = $order->projectData;

            $assigned = $order->personalDishes
                ->keyBy('meal_type_id')
                ->map(fn($pd) => $pd->dish_id)
                ->toArray();

            $mealTypes      = $client->mealTypes->sortBy('sort_order');
            $excludedIngIds = $client->ingredientExclusions->pluck('id')->toArray();

            $meals = [];
            foreach ($mealTypes as $mt) {
                $currentDishId  = $assigned[$mt->id] ?? null;
                $availableIds   = $this->getAvailableDishIds($excludedIngIds);

                $meals[] = [
                    'meal_type_id'    => $mt->id,
                    'meal_type_name'  => $mt->name,
                    'current_dish_id' => $currentDishId,
                    'available_ids'   => $availableIds,
                ];
            }

            $isEvening = str_contains((string)$order->schedule_type, 'evening');

            $this->cards[] = [
                'order_id'   => $order->id,
                'client_id'  => $client->id,
                'client'     => $client->name,
                'calories'   => (int)$order->calories,
                'project'    => $project?->name ?? ucfirst($order->project ?? ''),
                'color'      => $project?->color ?? 'gray',
                'is_evening' => $isEvening,
                'slot'       => $isEvening ? 'Вечір' : 'Ранок',
                'meals'      => $meals,
                'filled'     => count(array_filter($meals, fn($m) => $m['current_dish_id'])),
                'total'      => count($meals),
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
        $cycleDays  = (int)(Setting::where('key', 'menu_cycle_days')->value('value') ?: 24);
        $anchorDate = Carbon::parse('2025-01-01');
        $diff       = abs(Carbon::parse($date)->diffInDays($anchorDate));
        $globalDay  = ($diff % $cycleDays) + 1;

        $menu = DailyMenu::where('day_number', $globalDay)
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
            return array_keys($this->allDishes);
        }

        return Dish::whereDoesntHave('dishIngredients', fn($q) =>
            $q->whereIn('ingredient_id', $excludedIngIds)
        )->pluck('id')->toArray();
    }
}
