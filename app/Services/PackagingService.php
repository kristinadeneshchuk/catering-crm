<?php

namespace App\Services;

use App\Models\Dish;
use App\Models\Packaging;
use App\Traits\CalculatesOrderPlan;
use Illuminate\Support\Collection;

class PackagingService
{
    // Джерело грамажів — той самий трейт, що і в ShoppingList, ProductionReport,
    // PrintController. Гарантує, що якщо замовлення має індивідуальні цілі КБЖУ,
    // упаковка підбирається під РЕАЛЬНО перерахований грамаж, а не під стандарт.
    use CalculatesOrderPlan;


    /**
     * Вартість пакування для одного раціону (для аналітики).
     */
    public function calculateOrderPackagingCost($order, $menu, Collection $allPackaging, ?string $date = null): float
    {
        $items = $this->buildPackagingItems($order, $menu, $allPackaging, $date);
        return round(collect($items)->sum('total_price'), 2);
    }

    /**
     * Деталізований список упаковки для одного раціону (для менеджера).
     * Повертає масив: [['name', 'qty', 'unit_price', 'total_price', 'packaging_type', 'auto_pair'], ...]
     */
    public function getOrderPackagingBreakdown($order, $menu, Collection $allPackaging, ?string $date = null): array
    {
        return $this->buildPackagingItems($order, $menu, $allPackaging, $date);
    }

    /**
     * Зведений список упаковки для всіх замовлень на день.
     * Повертає: [packaging_id => ['name', 'total_qty', 'unit_price', 'total_cost'], ...]
     */
    public function getDailyPackagingSummary(Collection $orders, $menu, Collection $allPackaging, ?string $date = null): array
    {
        $summary = [];

        foreach ($orders as $order) {
            if (!$order->client) continue;
            $items = $this->buildPackagingItems($order, $menu, $allPackaging, $date);

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
     *
     * Грамажі беремо через calculateOrderPlan (той самий трейт, що і в
     * закупках / кухні / друку) — тому:
     *   - для звичайних замовлень поведінка 1-в-1 як раніше (kcal-only scale);
     *   - для замовлень з індивідуальними цілями КБЖУ грамажі вже після
     *     4-цільового LS, і контейнер підбирається під РЕАЛЬНУ порцію.
     */
    private function buildPackagingItems($order, $menu, Collection $allPackaging, ?string $date = null): array
    {
        if ((float)($order->calories ?? 0) <= 0) return [];

        $plan = $this->calculateOrderPlan($order, $menu, $date);
        if (empty($plan['items'])) return [];

        $orderProject         = $order->project ?? null;
        $clientDishExclusions = $order->client?->dishExclusions ?? collect();
        $replacements         = $order->replacements ?? collect();
        $result               = [];

        foreach ($plan['items'] as $planItem) {
            $originalDish = Dish::with('dishIngredients.childDish')->find($planItem['dish_id']);
            if (!$originalDish) continue;

            // Обробка повного виключення / заміни страви для цього клієнта.
            // Логіка збережена 1-в-1 як раніше, тільки перенесена сюди.
            $actualDish = $originalDish;
            if ($clientDishExclusions->contains('id', $originalDish->id)) {
                $fullRep = $replacements
                    ->whereNull('original_product_id')
                    ->where('dish_id', $originalDish->id)
                    ->first();

                if ($fullRep && $fullRep->replacementDish) {
                    $actualDish = $fullRep->replacementDish;
                    $actualDish->loadMissing('dishIngredients.childDish');
                } else {
                    // Виключено без заміни — контейнер не потрібен.
                    continue;
                }
            }

            if (!$actualDish->packaging_type) continue;

            $actualWeight = (float) $planItem['weight'];

            $container = $this->findContainer($allPackaging, $actualDish->packaging_type, $actualWeight, $orderProject);
            if (!$container) continue;

            $result[] = [
                'packaging_id'   => $container->id,
                'name'           => $container->name,
                'packaging_type' => $container->packaging_type,
                'dish_name'      => $actualDish->name,
                'actual_weight'  => (int) round($actualWeight),
                'qty'            => 1,
                'unit_price'     => (float) $container->price,
                'total_price'    => (float) $container->price,
                'auto_pair'      => false,
            ];

            // Автоматично додаємо пару (кришку / ковпачок).
            if ($container->pair_id) {
                $pair = $allPackaging->get($container->pair_id);
                if ($pair) {
                    $result[] = [
                        'packaging_id'   => $pair->id,
                        'name'           => $pair->name,
                        'packaging_type' => $pair->packaging_type,
                        'dish_name'      => $actualDish->name,
                        'actual_weight'  => null,
                        'qty'            => 1,
                        'unit_price'     => (float) $pair->price,
                        'total_price'    => (float) $pair->price,
                        'auto_pair'      => true,
                    ];
                }
            }

            // Упаковка НФ-інгредієнтів страви (реальної страви після заміни).
            // НФ мають фіксовану дозу, не масштабуються під ціль — це шматок
            // сталого рецепту, а не еластична порція.
            foreach ($actualDish->dishIngredients as $ingr) {
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
                    'actual_weight'  => $nfWeight > 0 ? (int) round($nfWeight) : null,
                    'qty'            => 1,
                    'unit_price'     => (float) $nfContainer->price,
                    'total_price'    => (float) $nfContainer->price,
                    'auto_pair'      => false,
                ];

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
