<?php

namespace App\Services\Kitchen;

use App\Models\Dish;
use App\Models\Order;
use App\Traits\CalculatesOrderPlan;
use Illuminate\Support\Collection;

/**
 * Індивідуальні клієнти у фасувальному листі — по прийомах їжі, поруч із циклічним.
 *
 * Кухня фасує по черзі: сніданок циклічного, потім індивідуальні сніданки, обід…
 * Тому:
 *   - індивідуальний з тією самою стравою, що й у циклічному (без виключень),
 *     стає окремою колонкою «🌙 #620 Олександр 3000» у таблиці циклічної страви;
 *   - решта індивідуальних страв цього прийому — блоком одразу під таблицею
 *     в погодженому форматі 11.10: зліва складові, колонки — клієнти
 *     (спершу вечірні), у клітинці грами, заміни червоним;
 *   - прийоми, яких немає в циклічному (додаток до прийому), — блоками в кінці.
 */
class IndividualPacking
{
    use CalculatesOrderPlan;

    private array $twoOrders = [];

    /**
     * @param  array  $tables  таблиці плану (циклічні мають dish_id і meal_type_id)
     * @param  array  $plans   [order_id => calculateOrderPlan()]
     */
    public function arrange(array $tables, Collection $orders, Collection $menuItems, array $plans): array
    {
        $individuals = $orders->filter(fn (Order $o) => $o->menu_type === 'individual' && !empty($plans[$o->id]['items']))
            ->sortBy(fn (Order $o) => [$this->isEvening($o) ? 0 : 1, $o->client?->name])
            ->values();
        if ($individuals->isEmpty()) {
            return $tables;
        }

        $cycleKeys = $menuItems->filter(fn ($mi) => $mi->dish)
            ->mapWithKeys(fn ($mi) => [$mi->dish_id.':'.$mi->meal_type_id => $mi])->all();
        $mealOrder = $menuItems->mapWithKeys(fn ($mi) => [$mi->meal_type_id => $mi->mealType?->sort_order ?? 99])->all();

        // Два замовлення одного клієнта на день (два раціони) — підписуємо номер замовлення.
        $this->twoOrders = $individuals->countBy(fn (Order $o) => $o->client_id)->filter(fn ($n) => $n > 1)->keys()->all();

        $blocks = []; // [meal_type_id => [meal, sort, dishes => [dish_id => ...]]]

        foreach ($individuals as $order) {
            foreach ($plans[$order->id]['items'] as $item) {
                $dish = Dish::with(['dishIngredients.ingredient', 'dishIngredients.childDish.dishIngredients.ingredient'])->find($item['dish_id']);
                if (!$dish) continue;

                $weight = (int) $item['weight'];
                $baseW  = (float) ($dish->base_weight_g ?? 0);
                $scale  = $baseW > 0 ? $weight / $baseW : 0.0;
                $key    = $dish->id.':'.$item['meal_type_id'];

                // Та сама страва, що в циклічному, і без змін — у таблицю циклічного.
                if (isset($cycleKeys[$key]) && !$this->isCustomForDish($order, $dish)) {
                    $tables = $this->addColumn($tables, $cycleKeys[$key], $dish, $order, $scale);
                    continue;
                }

                $mt = (int) $item['meal_type_id'];
                $blocks[$mt] ??= ['meal' => $item['meal'], 'meal_type_id' => $mt, 'dishes' => []];
                $blocks[$mt]['dishes'][$dish->id] ??= ['dish_name' => $dish->name, 'clients' => [], 'rows' => [], 'notes' => []];
                $this->addClient($blocks[$mt]['dishes'][$dish->id], $order, $dish, $scale, $weight);
            }
        }

        // Блок — одразу після останньої таблиці свого прийому в циклічному.
        $out = [];
        $lastCycleIdx = null;
        foreach ($tables as $i => $t) {
            if (isset($t['meal_type_id'])) $lastCycleIdx = $i;
        }
        foreach ($tables as $i => $t) {
            $out[] = $t;
            $mt = $t['meal_type_id'] ?? null;
            $nextMt = $tables[$i + 1]['meal_type_id'] ?? null;
            if ($mt !== null && $mt !== $nextMt && isset($blocks[$mt])) {
                $out[] = $this->block($blocks[$mt]);
                unset($blocks[$mt]);
            }
            if ($i === $lastCycleIdx) {
                // Прийоми без циклічної страви — одразу після циклічних, за порядком прийомів.
                uasort($blocks, fn ($a, $b) => ($mealOrder[$a['meal_type_id']] ?? 99) <=> ($mealOrder[$b['meal_type_id']] ?? 99));
                foreach ($blocks as $b) $out[] = $this->block($b);
                $blocks = [];
            }
        }
        foreach ($blocks as $b) $out[] = $this->block($b);

        return $out;
    }

    private function addColumn(array $tables, $menuItem, Dish $dish, Order $order, float $scale): array
    {
        $idx = null;
        foreach ($tables as $i => $t) {
            if (($t['dish_id'] ?? null) === (int) $dish->id && ($t['meal_type_id'] ?? null) === (int) $menuItem->meal_type_id) {
                $idx = $i;
                break;
            }
        }
        if ($idx === null) {
            // Циклічну страву сьогодні їдять лише індивідуальні — таблиця з нуля.
            $tables[] = ['meal' => $menuItem->mealType?->name ?? 'Інше', 'dish_name' => $dish->name,
                'dish_id' => (int) $dish->id, 'meal_type_id' => (int) $menuItem->meal_type_id,
                'columns' => [], 'individual_notes' => [],
                'rows' => $dish->dishIngredients->map(fn ($di) => ['original_name' => $this->ingredientRowLabel($di), 'cells' => []])->all()];
            $idx = array_key_last($tables);
        }
        if (empty($tables[$idx]['rows'])) {
            // Таблиця була лише з примітками (усі стандартні клієнти з замінами).
            $tables[$idx]['rows'] = $dish->dishIngredients->map(fn ($di) => ['original_name' => $this->ingredientRowLabel($di), 'cells' => []])->all();
        }

        $colKey = 'ind-'.$order->id;
        $slug   = $order->project ?? 'none';
        $tables[$idx]['columns'][$colKey] = [
            'label'        => $this->clientLabel($order),
            'count'        => 1,
            'sum_scale'    => $scale,
            'custom_count' => 0,
            'individual'   => true,
            'projects'     => [$slug => ['name' => $order->projectData?->name ?? ucfirst($slug), 'count' => 1, 'custom_count' => 0]],
        ];
        foreach ($dish->dishIngredients->values() as $r => $di) {
            if (isset($tables[$idx]['rows'][$r])) {
                $tables[$idx]['rows'][$r]['cells'][$colKey] = round((float) ($di->net_weight_g ?? 0) * $scale);
            }
        }

        return $tables;
    }

    private function addClient(array &$dishBlock, Order $order, Dish $dish, float $scale, int $weight): void
    {
        $c = count($dishBlock['clients']);
        $dishBlock['clients'][] = ['label' => $this->clientLabel($order), 'weight' => $weight];

        foreach ($this->buildCustomClientRows($order, $dish, $scale) as $row) {
            $changed = str_starts_with($row['name'], '↔') || str_contains($row['name'], 'ВИКЛЮЧЕНО');
            $dishBlock['rows'][$row['name']] ??= ['name' => $row['name'], 'changed' => $changed, 'cells' => []];
            $dishBlock['rows'][$row['name']]['cells'][$c] = $row['weight'];
        }

        if ($note = $this->individualNote($order, $dish)) {
            foreach ($note['changes'] as $text) {
                $dishBlock['notes'][] = ['client' => $this->clientLabel($order), 'text' => $text];
            }
        }
    }

    private function block(array $b): array
    {
        return [
            'is_ind_block' => true,
            'meal'         => $b['meal'],
            'dishes'       => array_map(fn ($d) => ['rows' => array_values($d['rows'])] + $d, array_values($b['dishes'])),
        ];
    }

    private function clientLabel(Order $order): string
    {
        // Коротко, щоб таблиця влазила в аркуш: «🌙 #481 Jiri Svoboda · 2300».
        $name = trim(preg_replace('/\s*\(.*?\)\s*/u', ' ', (string) $order->client?->name));
        $name = implode(' ', array_slice(preg_split('/\s+/u', $name), 0, 2));

        return ($this->isEvening($order) ? '🌙 ' : '☀ ').'#'.$order->client?->id.' '.$name.' · '.(int) $order->calories
            .(in_array($order->client_id, $this->twoOrders) ? ' (зам. '.$order->id.')' : '');
    }

    private function isEvening(Order $order): bool
    {
        return str_contains((string) $order->schedule_type, 'evening');
    }
}
