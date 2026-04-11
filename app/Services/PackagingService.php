<?php

namespace App\Services;

use App\Models\MealPlan;
use App\Models\Packaging;
use Illuminate\Support\Collection;

class PackagingService
{
    /**
     * Вартість пакування для одного раціону (для аналітики).
     */
    public function calculateOrderPackagingCost($order, $menu, Collection $allPackaging): float
    {
        $items = $this->buildPackagingItems($order, $menu, $allPackaging);
        return round(collect($items)->sum('total_price'), 2);
    }

    /**
     * Деталізований список упаковки для одного раціону (для менеджера).
     * Повертає масив: [['name', 'qty', 'unit_price', 'total_price', 'packaging_type', 'auto_pair'], ...]
     */
    public function getOrderPackagingBreakdown($order, $menu, Collection $allPackaging): array
    {
        return $this->buildPackagingItems($order, $menu, $allPackaging);
    }

    /**
     * Зведений список упаковки для всіх замовлень на день.
     * Повертає: [packaging_id => ['name', 'total_qty', 'unit_price', 'total_cost'], ...]
     */
    public function getDailyPackagingSummary(Collection $orders, $menu, Collection $allPackaging): array
    {
        $summary = [];

        foreach ($orders as $order) {
            if (!$order->client) continue;
            $items = $this->buildPackagingItems($order, $menu, $allPackaging);

            foreach ($items as $item) {
                $id = $item['packaging_id'];
                if (!isset($summary[$id])) {
                    $summary[$id] = [
                        'name'           => $item['name'],
                        'packaging_type' => $item['packaging_type'],
                        'unit_price'     => $item['unit_price'],
                        'total_qty'      => 0,
                        'total_cost'     => 0,
                    ];
                }
                $summary[$id]['total_qty']  += $item['qty'];
                $summary[$id]['total_cost'] += $item['total_price'];
            }
        }

        // Сортуємо: спочатку боксі та пляшки, потім решта
        $order = ['бокс' => 1, 'кришка' => 2, 'пляшка' => 3, 'ковпачок' => 4, 'пакет' => 5, 'прибори' => 6, 'наклейка' => 7, 'серветка' => 8];
        uasort($summary, fn($a, $b) => ($order[$a['packaging_type']] ?? 99) <=> ($order[$b['packaging_type']] ?? 99));

        return $summary;
    }

    // ============================================================
    // ПРИВАТНА ЛОГІКА
    // ============================================================

    /**
     * Основний метод — будує список упаковки для одного замовлення.
     */
    private function buildPackagingItems($order, $menu, Collection $allPackaging): array
    {
        $targetKcal = (float)($order->calories ?? 0);
        if ($targetKcal <= 0) return [];

        $clientMealTypeIds = $order->client?->mealTypes?->pluck('id')->toArray() ?? [];

        $availableItems = $menu->menuItems
            ->filter(fn ($item) => $item->dish && in_array($item->meal_type_id, $clientMealTypeIds, true))
            ->sortBy(fn ($item) => $item->mealType?->sort_order ?? 99)
            ->values();

        if ($availableItems->isEmpty()) return [];

        $allowedSortOrders = MealPlan::getAllowedSortOrders((int)$targetKcal);
        $selectedItems = $availableItems->filter(
            fn ($item) => in_array($item->mealType?->sort_order, $allowedSortOrders)
        )->values();

        if ($selectedItems->isEmpty()) return [];

        $byMeal = $selectedItems->groupBy('meal_type_id');

        // Нормалізація відсотків
        $rawPct = [];
        foreach ($byMeal as $mealTypeId => $items) {
            $fi = $items->first();
            $rawPct[$mealTypeId] = $fi->custom_energy_percent !== null
                ? (float) $fi->custom_energy_percent
                : (float) ($fi->mealType?->energy_percent ?? 0);
        }
        $totalPct   = array_sum($rawPct);
        $normFactor = ($totalPct > 0.5 && abs($totalPct - 100) > 0.5) ? (100.0 / $totalPct) : 1.0;

        $result       = [];
        $orderProject = $order->project ?? null;

        foreach ($byMeal as $mealTypeId => $items) {
            $p        = ($rawPct[$mealTypeId] ?? 0) * $normFactor;
            $mealKcal = ($p > 0)
                ? $targetKcal * ($p / 100.0)
                : $targetKcal * (1.0 / max(1, $byMeal->count()));

            $kcalPerDish = $mealKcal / max(1, $items->count());

            foreach ($items as $mi) {
                $dish = $mi->dish;
                if (!$dish || !$dish->packaging_type) continue;

                // Фактична вага порції
                $baseW      = (float)($dish->base_weight_g ?? 0);
                $totalKcal  = (float)($dish->total_kcal ?? 0);
                $kcalPer100 = ($baseW > 0 && $totalKcal > 0) ? ($totalKcal / $baseW) * 100.0 : 0;
                $actualWeight = ($kcalPer100 > 0) ? ($kcalPerDish / $kcalPer100) * 100.0 : $baseW;

                // Підбираємо контейнер
                $container = $this->findContainer($allPackaging, $dish->packaging_type, $actualWeight, $orderProject);
                if (!$container) continue;

                $result[] = [
                    'packaging_id'   => $container->id,
                    'name'           => $container->name,
                    'packaging_type' => $container->packaging_type,
                    'dish_name'      => $dish->name,
                    'actual_weight'  => round($actualWeight),
                    'qty'            => 1,
                    'unit_price'     => (float) $container->price,
                    'total_price'    => (float) $container->price,
                    'auto_pair'      => false,
                ];

                // Автоматично додаємо пару (кришку / ковпачок)
                if ($container->pair_id) {
                    $pair = $allPackaging->get($container->pair_id);
                    if ($pair) {
                        $result[] = [
                            'packaging_id'   => $pair->id,
                            'name'           => $pair->name,
                            'packaging_type' => $pair->packaging_type,
                            'dish_name'      => $dish->name,
                            'actual_weight'  => null,
                            'qty'            => 1,
                            'unit_price'     => (float) $pair->price,
                            'total_price'    => (float) $pair->price,
                            'auto_pair'      => true,
                        ];
                    }
                }

                // Упаковка НФ-інгредієнтів страви
                $dish->loadMissing('dishIngredients.childDish');
                foreach ($dish->dishIngredients as $ingr) {
                    $nf = $ingr->childDish;
                    if (!$nf || !$nf->packaging_type) continue;

                    $nfWeight    = (float)($ingr->net_weight_g ?? 0);
                    $nfContainer = $this->findContainer($allPackaging, $nf->packaging_type, $nfWeight, $orderProject);
                    if (!$nfContainer) continue;

                    $result[] = [
                        'packaging_id'   => $nfContainer->id,
                        'name'           => $nfContainer->name,
                        'packaging_type' => $nfContainer->packaging_type,
                        'dish_name'      => $nf->name,
                        'actual_weight'  => $nfWeight > 0 ? round($nfWeight) : null,
                        'qty'            => 1,
                        'unit_price'     => (float) $nfContainer->price,
                        'total_price'    => (float) $nfContainer->price,
                        'auto_pair'      => false,
                    ];

                    // Пара для НФ-контейнера (наприклад, кришка для соусника)
                    if ($nfContainer->pair_id) {
                        $nfPair = $allPackaging->get($nfContainer->pair_id);
                        if ($nfPair) {
                            $result[] = [
                                'packaging_id'   => $nfPair->id,
                                'name'           => $nfPair->name,
                                'packaging_type' => $nfPair->packaging_type,
                                'dish_name'      => $nf->name,
                                'actual_weight'  => null,
                                'qty'            => 1,
                                'unit_price'     => (float) $nfPair->price,
                                'total_price'    => (float) $nfPair->price,
                                'auto_pair'      => true,
                            ];
                        }
                    }
                }
            }
        }

        // +1 пакет на раціон (по бренду)
        $packet = $this->findPacket($allPackaging, $orderProject);
        if ($packet) {
            $result[] = [
                'packaging_id'   => $packet->id,
                'name'           => $packet->name,
                'packaging_type' => 'пакет',
                'dish_name'      => null,
                'actual_weight'  => null,
                'qty'            => 1,
                'unit_price'     => (float) $packet->price,
                'total_price'    => (float) $packet->price,
                'auto_pair'      => false,
            ];
        }

        // +1 комплект приборів — тільки якщо клієнт замовляє прибори
        if (!($order->client?->has_cutlery ?? true)) return $result;

        $cutlery = $allPackaging->filter(fn ($p) => $p->packaging_type === 'прибори');
        foreach ($cutlery as $item) {
            $result[] = [
                'packaging_id'   => $item->id,
                'name'           => $item->name,
                'packaging_type' => 'прибори',
                'dish_name'      => null,
                'actual_weight'  => null,
                'qty'            => 1,
                'unit_price'     => (float) $item->price,
                'total_price'    => (float) $item->price,
                'auto_pair'      => false,
            ];
        }

        return $result;
    }

    /**
     * Найменший контейнер потрібного типу що вміщає фактичну вагу.
     * Пріоритет: проект замовлення → загальний → будь-який.
     */
    private function findContainer(Collection $allPackaging, string $type, float $weight, ?string $project): ?Packaging
    {
        $candidates = $allPackaging
            ->filter(fn ($p) =>
                $p->packaging_type === $type &&
                $p->capacity !== null &&
                (float) $p->capacity >= $weight
            )
            ->sortBy('capacity');

        if ($project) {
            $match = $candidates->first(fn ($p) => $p->project === $project);
            if ($match) return $match;
        }

        return $candidates->first(fn ($p) => is_null($p->project)) ?? $candidates->first();
    }

    /**
     * Пакет по проекту замовлення.
     */
    private function findPacket(Collection $allPackaging, ?string $project): ?Packaging
    {
        $packets = $allPackaging->filter(fn ($p) => $p->packaging_type === 'пакет');

        if ($project) {
            $match = $packets->first(fn ($p) => $p->project === $project);
            if ($match) return $match;
        }

        return $packets->first(fn ($p) => is_null($p->project)) ?? $packets->first();
    }
}
