<?php

namespace App\Services\Kitchen;

use App\Models\Dish;
use App\Models\LunchOrderLine;
use App\Support\SchemaReady;
use Illuminate\Support\Collection;

/**
 * Корпоративні обіди з Lunch Hub у кухонних документах дня.
 *
 * Обід — це порції тих самих страв циклу, тільки замовлені компаніями. Тому в
 * плані виробництва й списку покупок вони йдуть у загальний котел разом із
 * раціонами, а у фасувальному — окремою колонкою «Обіди» з розбивкою по
 * компаніях. Розкладка по людях — окремий «зборочний» документ, не тут.
 *
 * Один помічник на всі копії розрахунку (ProductionPlanBuilder, PackagingList,
 * ShoppingList і друковані версії в PrintController), щоб вони не розʼїхались.
 */
final class LunchKitchen
{
    /** Ключ колонки у фасувальному: більший за будь-який калораж — стає останнім. */
    public const COLUMN = 100000;

    /** @var array<int, array<int, array{qty:int, weight_g:?int, company_id:int, company_name:string}>> */
    private array $byDish = [];

    /** @var array<int, true> */
    private array $taken = [];

    /** Обіди з датою доставки $date (готуються напередодні, як і раціони). */
    public static function forDate(string $date): self
    {
        $self = new self();
        if (! SchemaReady::has('lunch_orders') || ! SchemaReady::has('lunch_order_lines')) {
            return $self;
        }

        $rows = LunchOrderLine::query()
            ->join('lunch_orders', 'lunch_orders.id', '=', 'lunch_order_lines.lunch_order_id')
            ->whereDate('lunch_orders.date', $date)
            ->whereNotNull('lunch_order_lines.dish_id')
            ->where('lunch_order_lines.qty', '>', 0)
            // Без ваги порції рахувати нічим: базова вага техкарти — це часто вся
            // закладка, а не порція. Такі рядки лишаються тільки на сторінці «Обіди».
            ->where('lunch_order_lines.weight_g', '>', 0)
            ->orderBy('lunch_orders.company_name')
            ->get([
                'lunch_order_lines.dish_id', 'lunch_order_lines.qty', 'lunch_order_lines.weight_g',
                'lunch_orders.company_id', 'lunch_orders.company_name',
            ]);

        foreach ($rows as $r) {
            $self->byDish[(int) $r->dish_id][] = [
                'qty'          => (int) $r->qty,
                'weight_g'     => (int) $r->weight_g,
                'company_id'   => (int) $r->company_id,
                'company_name' => (string) $r->company_name,
            ];
        }

        return $self;
    }

    public function isEmpty(): bool
    {
        return $this->byDish === [];
    }

    /** @return array<int, array<int, array{qty:int, weight_g:?int, company_id:int, company_name:string}>> */
    public function all(): array
    {
        return $this->byDish;
    }

    /** Рядки обідів для страви — один раз за документ (страва може стояти в меню двічі). */
    public function take(int $dishId): array
    {
        if (isset($this->taken[$dishId]) || empty($this->byDish[$dishId])) {
            return [];
        }
        $this->taken[$dishId] = true;

        return $this->byDish[$dishId];
    }

    /** Масштаб техкарти для порції обіду. */
    public static function scale($dish, ?int $weightG): float
    {
        if (! $weightG) {
            return 0.0;
        }
        $base = (float) ($dish->base_weight_g ?? 0);

        return $base > 0 ? $weightG / $base : 0.0;
    }

    /** Сумарний масштаб усіх обідніх порцій страви — для списку покупок. */
    public static function totalScale($dish, array $lines): float
    {
        return array_sum(array_map(fn ($l) => self::scale($dish, $l['weight_g']) * $l['qty'], $lines));
    }

    /**
     * План виробництва: обідні порції — у загальний котел страви.
     *
     * @return int скільки порцій додано
     */
    public function pushStandard(array &$standard, $dish): int
    {
        $count = 0;
        foreach ($this->take((int) $dish->id) as $l) {
            $scale = self::scale($dish, $l['weight_g']);
            for ($i = 0; $i < $l['qty']; $i++) {
                $standard[] = ['order' => null, 'scale' => $scale];
            }
            $count += $l['qty'];
        }

        return $count;
    }

    /** Фасувальний: колонка «Обіди» з розбивкою по компаніях. */
    public function addPackingColumn(array &$tableData, $dish): void
    {
        foreach ($this->take((int) $dish->id) as $l) {
            if (! isset($tableData['columns'][self::COLUMN])) {
                $tableData['columns'][self::COLUMN] = [
                    'label' => 'Обіди', 'count' => 0, 'sum_scale' => 0.0, 'projects' => [], 'custom_count' => 0,
                ];
            }
            $col = &$tableData['columns'][self::COLUMN];
            $col['count'] += $l['qty'];
            $col['sum_scale'] += self::scale($dish, $l['weight_g']) * $l['qty'];

            $key = 'lunch-'.$l['company_id'];
            if (! isset($col['projects'][$key])) {
                $col['projects'][$key] = ['name' => $l['company_name'], 'count' => 0, 'custom_count' => 0];
            }
            $col['projects'][$key]['count'] += $l['qty'];
            unset($col);
        }
    }

    /**
     * Обідні страви, яких немає в меню дня (цикл поміняли після замовлення):
     * додаємо їх до останнього плану окремим прийомом «Обіди», щоб не загубити.
     */
    public function extraItems($planId, Collection $ordersByPlan, $menu): Collection
    {
        if ($this->isEmpty() || $planId !== $ordersByPlan->keys()->last()) {
            return collect();
        }

        $inMenu = $menu->menuItems->pluck('dish_id')->map(fn ($id) => (int) $id)->all();
        $ids = array_values(array_diff(array_keys($this->byDish), array_keys($this->taken), $inMenu));
        if ($ids === []) {
            return collect();
        }

        return Dish::with([
            'dishIngredients.ingredient.allergens',
            'dishIngredients.childDish.dishIngredients.ingredient.allergens',
        ])->whereIn('id', $ids)->orderBy('name')->get()->map(fn (Dish $dish) => (object) [
            'dish'         => $dish,
            'dish_id'      => (int) $dish->id,
            'meal_type_id' => 0,
            'mealType'     => (object) ['name' => 'Обіди', 'sort_order' => 999],
        ]);
    }
}
