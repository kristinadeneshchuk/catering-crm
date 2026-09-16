<?php

namespace App\Services\Kitchen;

use App\Models\DailyMenu;
use App\Models\Dish;
use App\Models\MenuPlan;
use App\Models\Order;
use App\Models\Packaging;
use App\Services\PackagingService;
use App\Traits\CalculatesOrderPlan;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * План виробництва на один день їжі: які страви, в якій кількості, з якими
 * замінами — і скільки сировини та упаковки на це йде за нормою техкарт.
 *
 * Винесено зі сторінки «План виробництва», щоб те саме рахували і кнопка
 * «Закрити зміну», і автоматичне списання о 06:00 (stock:debit-norm).
 */
class ProductionPlanBuilder
{
    use CalculatesOrderPlan;

    /** @var array<int, array{items: array, totals: array}> */
    private array $orderPlans = [];

    /**
     * @return array{
     *   target_date: string,
     *   orders: Collection,
     *   report: array,
     *   missing_plans: array,
     *   day_number: ?float,
     *   debug: ?string,
     * }
     */
    public function build(string $targetDate): array
    {
        $targetDateObj = Carbon::parse($targetDate)->startOfDay();
        $targetDate = $targetDateObj->format('Y-m-d');

        $report = [];
        $missingPlans = [];
        $this->orderPlans = [];

        $activeOrders = Order::feedingOn($targetDate)
            ->with([
                'client.mealTypes',
                'client.ingredientExclusions',
                'client.dishExclusions',
                'client.replacementBundles.items.originalIngredient',
                'client.replacementBundles.items.replacementIngredient',
                'ingredientExclusions',
                'menuPlan',
                'replacements.replacementProduct',
                'replacements.replacementDish.dishIngredients.ingredient',
                'replacements.replacementDish.dishIngredients.childDish.dishIngredients.ingredient',
                'projectData',
            ])
            ->get();

        if ($activeOrders->isEmpty()) {
            return [
                'target_date'   => $targetDate,
                'orders'        => $activeOrders,
                'report'        => [],
                'missing_plans' => [],
                'day_number'    => null,
                'debug'         => null,
            ];
        }

        // Групуємо замовлення по планах меню
        $ordersByPlan = $activeOrders->groupBy(fn ($o) => $o->effectiveMenuPlan()?->id ?? 0);

        $debugLines = [];

        foreach ($ordersByPlan as $planId => $planOrders) {
            $plan = $planOrders->first()->effectiveMenuPlan();
            if (!$plan) continue;

            $dayNumber = $plan->globalDayFor($targetDateObj);
            $debugLines[] = "{$plan->name} — день {$dayNumber}";

            $menu = DailyMenu::where('menu_plan_id', $plan->id)
                ->where('day_number', $dayNumber)
                ->with([
                    'menuItems.dish.dishIngredients.ingredient.allergens',
                    'menuItems.dish.dishIngredients.childDish.dishIngredients.ingredient.allergens',
                    'menuItems.mealType',
                ])
                ->first();

            if (!$menu) {
                $missingPlans[] = [
                    'plan'         => $plan,
                    'day_number'   => $dayNumber,
                    'orders_count' => $planOrders->count(),
                    'client_names' => $planOrders->map(fn ($o) => $o->client?->name)->filter()->unique()->take(5)->values()->all(),
                ];
                continue;
            }

            // Розраховуємо план для кожного замовлення в цьому плані меню
            foreach ($planOrders as $order) {
                $this->orderPlans[$order->id] = $this->calculateOrderPlan($order, $menu, $targetDate);
            }

            $planMeals = [];
            $sortedMenuItems = $menu->menuItems->sortBy(fn ($item) => $item->mealType?->sort_order ?? 99);

            foreach ($sortedMenuItems as $item) {
                if (!$item->dish) continue;

                $mealName = $item->mealType->name ?? 'Інше';
                $dish = $item->dish;

                $standard = [];
                $custom = [];
                $commentClients = [];

                foreach ($planOrders as $order) {
                    if ($order->menu_type === 'individual') continue;

                    $orderPlan = $this->orderPlans[$order->id] ?? null;
                    if (!$orderPlan) continue;

                    $plannedWeight = $this->plannedDishWeight($orderPlan['items'], (int)$dish->id, (int)$item->meal_type_id);
                    if ($plannedWeight === null) continue;

                    $baseW = (float)($dish->base_weight_g ?? 0);
                    $dishScale = ($baseW > 0) ? ((float)$plannedWeight / $baseW) : 0.0;

                    $isCustom = $this->isCustomForDish($order, $dish);

                    if (!empty(trim($order->client->production_comment ?? ''))) {
                        $commentClients[] = [
                            'client_name' => $order->client->name,
                            'order_id'    => $order->id,
                            'comment'     => trim($order->client->production_comment),
                        ];
                    }

                    if ($isCustom) {
                        $custom[] = ['order' => $order, 'scale' => $dishScale];
                    } else {
                        $standard[] = ['order' => $order, 'scale' => $dishScale];
                    }
                }

                if (empty($standard) && empty($custom)) continue;

                $standardScales = array_map(fn ($x) => (float)$x['scale'], $standard);

                $standardStructure = $this->calculateIngredientsStructureByScales($dish, $standardScales);
                $standardTotals = $this->calculateTotals($standardStructure);

                $customCards = collect($custom)->map(function ($entry) use ($dish) {
                    return $this->buildCustomCard($dish, $entry['order'], (float)$entry['scale']);
                })->toArray();

                $planMeals[$mealName][] = [
                    'meal_name' => $mealName,
                    'dish_id' => $dish->id,
                    'dish_name' => $dish->name,
                    'standard_count' => count($standard),
                    'standard_structure' => $standardStructure,
                    'standard_total_netto' => $standardTotals['netto'],
                    'standard_total_brutto' => $standardTotals['brutto'],
                    'custom_cards' => $customCards,
                    'comment_clients' => $commentClients,
                ];
            }

            // === ІНДИВІДУАЛЬНІ КЛІЄНТИ цього плану ===
            // Preload all dishes for individual clients in one query to avoid N+1
            $individualDishIds = [];
            foreach ($planOrders as $order) {
                if ($order->menu_type !== 'individual') continue;
                $orderPlan = $this->orderPlans[$order->id] ?? null;
                if (!$orderPlan || empty($orderPlan['items'])) continue;
                foreach ($orderPlan['items'] as $item) {
                    $individualDishIds[] = $item['dish_id'];
                }
            }
            $individualDishesMap = collect();
            if (!empty($individualDishIds)) {
                $individualDishesMap = Dish::with([
                    'dishIngredients.ingredient.allergens',
                    'dishIngredients.childDish.dishIngredients.ingredient.allergens',
                ])->whereIn('id', array_unique($individualDishIds))->get()->keyBy('id');
            }

            $planIndividuals = [];
            foreach ($planOrders as $order) {
                if ($order->menu_type !== 'individual') continue;

                $orderPlan = $this->orderPlans[$order->id] ?? null;
                if (!$orderPlan || empty($orderPlan['items'])) continue;

                $oid = $order->id;
                $meals = [];

                foreach ($orderPlan['items'] as $item) {
                    $dish = $individualDishesMap->get($item['dish_id']);
                    if (!$dish) continue;

                    $weight = (int)$item['weight'];
                    $baseW  = (float)($dish->base_weight_g ?? 0);
                    $scale  = $baseW > 0 ? $weight / $baseW : 0.0;

                    // Клієнтам на індивідуальному меню картка малювалась без
                    // перевірки виключень — кухня бачила звичайну рецептуру навіть
                    // тоді, коли в анкеті стоїть «не їсть». Рахуємо так само, як
                    // для циклічних карток: з виключеннями і рішеннями менеджера.
                    $components = $this->getHierarchicalIngredients($dish, $scale, 1.0, null, true, $order);
                    $totals     = $this->calculateTotals($components);

                    $meals[] = [
                        'meal'          => $item['meal'],
                        'dish_name'     => $dish->name,
                        'components'    => $components,
                        'total_netto'   => $totals['netto'],
                        'total_brutto'  => $totals['brutto'],
                        'cooking_note'  => $item['cooking_note'] ?? null,
                    ];
                }

                // Лікувальна дієта клієнта — кухня має бачити її правила
                // над стравами, а не шукати в картці клієнта.
                $diet = $order->client->diet;

                $planIndividuals[$oid] = [
                    'client_label' => '#' . $order->client->id . ' ' . $order->client->name,
                    'calories'     => (int)($order->calories ?? 0),
                    'project'      => $order->projectData?->name ?? ucfirst($order->project ?? ''),
                    'meals'        => $meals,
                    'diet_label'   => $diet?->label(),
                    'diet_kitchen' => $diet?->kitchen_note,
                    'diet_cooking' => $diet?->cooking_methods,
                ];
            }

            if (empty($planMeals) && empty($planIndividuals)) continue;

            $report[$plan->id] = [
                'plan'        => $plan,
                'day_number'  => $dayNumber,
                'meals'       => $planMeals,
                'individuals' => $planIndividuals,
            ];
        }

        // Дефолтний день циклу — для зворотної сумісності з заголовками
        $defaultPlan = MenuPlan::default();

        return [
            'target_date'   => $targetDate,
            'orders'        => $activeOrders,
            'report'        => $report,
            'missing_plans' => $missingPlans,
            'day_number'    => $defaultPlan ? $defaultPlan->globalDayFor($targetDateObj) : 0,
            'debug'         => "🍳 Готуємо сьогодні на завтра: " . $targetDateObj->format('d.m.Y')
                . ($debugLines ? ' · ' . implode(' · ', $debugLines) : ''),
        ];
    }

    /**
     * Брутто-грами кожного інгредієнта за звітом дня: стандарт + персональні
     * картки + індивідуальні меню. Якщо виключений продукт замінено — рахуємо
     * замінник; повністю виключену й не замінену страву пропускаємо.
     *
     * @return array<int, float> ingredient_id => грами брутто
     */
    public function collectIngredientGrams(array $report): array
    {
        $grams = [];

        foreach ($report as $planData) {
            foreach (($planData['meals'] ?? []) as $mealDishes) {
                foreach ($mealDishes as $dishData) {
                    foreach ($dishData['standard_structure'] as $comp) {
                        $this->collectIngredientsRecursive($comp, $grams);
                    }
                    foreach ($dishData['custom_cards'] as $card) {
                        if ($card['dish_excluded'] && empty($card['replacement_dish_id'])) continue;
                        foreach ($card['components'] as $comp) {
                            $this->collectIngredientsRecursive($comp, $grams);
                        }
                    }
                }
            }

            foreach (($planData['individuals'] ?? []) as $clientData) {
                foreach ($clientData['meals'] as $meal) {
                    foreach ($meal['components'] as $comp) {
                        $this->collectIngredientsRecursive($comp, $grams);
                    }
                }
            }
        }

        return $grams;
    }

    /**
     * Кількість кожного виду упаковки на день їжі (через PackagingService).
     *
     * @return array<int, int> packaging_id => штук
     */
    public function collectPackagingQty(Collection $orders, string $targetDate): array
    {
        if ($orders->isEmpty()) return [];

        $targetDateObj = Carbon::parse($targetDate);

        $allPackaging = Packaging::whereNotNull('packaging_type')->get()->keyBy('id');
        $service = new PackagingService();

        // Кожен план меню має свій день циклу і своє меню
        $ordersByPlan = $orders->groupBy(fn ($o) => $o->effectiveMenuPlan()?->id ?? 0);

        $toDebit = [];

        foreach ($ordersByPlan as $planOrders) {
            $plan = $planOrders->first()->effectiveMenuPlan();
            if (!$plan) continue;

            $dayNum = $plan->globalDayFor($targetDateObj);

            $menu = DailyMenu::with([
                'menuItems.dish.dishIngredients.childDish',
                'menuItems.mealType',
            ])
                ->where('menu_plan_id', $plan->id)
                ->where('day_number', $dayNum)
                ->first();

            if (!$menu) continue;

            $summary = $service->getDailyPackagingSummary($planOrders, $menu, $allPackaging, $targetDateObj->format('Y-m-d'));

            foreach ($summary as $packagingId => $item) {
                $qty = (int) round($item['total_qty'] ?? 0);
                if ($qty > 0) {
                    $toDebit[$packagingId] = ($toDebit[$packagingId] ?? 0) + $qty;
                }
            }
        }

        return $toDebit;
    }

    private function collectIngredientsRecursive(array $component, array &$accumulator): void
    {
        if (($component['type'] ?? null) === 'product') {
            
            $conflict = $component['conflict'] ?? null;
            
            // Если была успешная замена ингредиента, списываем ТОЛЬКО продукт-заменитель
            if (is_array($conflict) && ($conflict['is_resolved'] ?? false) && isset($conflict['replacement']['product_id'])) {
                $id = (int)$conflict['replacement']['product_id'];
                $weight = (float)($conflict['replacement']['brutto'] ?? 0);
            } else {
                // Иначе списываем оригинальный продукт
                $id = (int)($component['product_id'] ?? 0);
                $weight = (float)($component['weight_brutto'] ?? 0);
            }

            if ($id > 0) {
                if (!isset($accumulator[$id])) {
                    $accumulator[$id] = 0.0;
                }
                $accumulator[$id] += $weight;
            }
            return;
        }

        if (($component['type'] ?? null) === 'pf' && isset($component['sub_ingredients']) && is_array($component['sub_ingredients'])) {
            foreach ($component['sub_ingredients'] as $sub) {
                $this->collectIngredientsRecursive($sub, $accumulator);
            }
        }
    }

    private function calculateIngredientsStructureByScales($dish, array $scales): array
    {
        if (empty($scales)) return [];
        $totalScale = array_sum(array_map(fn ($s) => (float)$s, $scales));
        return $this->getHierarchicalIngredients($dish, $totalScale, 1.0, null, false, null);
    }

    private function buildCustomCard($dish, $order, float $scale): array
    {
        $dishForcedApproval = $order->replacements
            ->where('dish_id', $dish->id)
            ->whereNull('original_product_id')
            ->where('force_approved', true)
            ->first();

        $dishExclusion = !$dishForcedApproval && $order->client->dishExclusions->contains('id', $dish->id);
        $dishReplacement = $order->replacements->where('dish_id', $dish->id)->whereNull('original_product_id')->where('force_approved', false)->first();

        $replacementDishName = null;
        $replacementDishId = null;

        if ($dishReplacement && $dishReplacement->replacementDish) {
            $replacementDishName = $dishReplacement->replacementDish->name;
            $replacementDishId = $dishReplacement->replacementDish->id;

            $components = $this->getHierarchicalIngredients(
                $dishReplacement->replacementDish,
                $scale,
                1.0,
                $dishReplacement->replacementDish->id,
                true,
                $order
            );
        } else {
            $components = $this->getHierarchicalIngredients(
                $dish,
                $scale,
                1.0,
                $dish->id,
                true,
                $order
            );
        }

        $totals = $this->calculateTotals($components);
        
        $finalComment = trim($order->client->production_comment ?? '');

        return [
            'client_name' => $order->client->name,
            'order_id' => $order->id,
            'comment' => $finalComment,
            'dish_excluded' => $dishExclusion,
            'dish_replacement' => $replacementDishName,
            'replacement_dish_id' => $replacementDishId,
            'components' => $components,
            'total_netto' => $totals['netto'],
            'total_brutto' => $totals['brutto'],
            'excluded_ingredients' => $this->effectiveExclusions($order)->pluck('name')->values()->all(),
            'excluded_dishes' => $order->client->dishExclusions->pluck('name')->values()->all(),
            'bundles' => $order->client->replacementBundles->pluck('name')->values()->all(),
        ];
    }

    private function calculateTotals(array $components): array
    {
        $netto = 0.0;
        $brutto = 0.0;

        foreach ($components as $comp) {
            if (($comp['type'] ?? null) === 'pf') {
                $netto += (float)($comp['weight_output'] ?? 0);
                $brutto += (float)($comp['weight_brutto_sum'] ?? 0);
            } else {
                $netto += (float)($comp['weight_netto'] ?? 0);
                $brutto += (float)($comp['weight_brutto'] ?? 0);
            }
        }

        return ['netto' => round($netto), 'brutto' => round($brutto)];
    }

    /**
     * ✅ ВАЖНО: PF масштабируем по выходу (output_weight), а не по сумме закладки.
     * - Для продукта: netto -> brutto через yield%
     * - Для PF: берём долю = (вес_готового_ПФ_в_блюде) / (выход_ПФ)
     */
    private function getHierarchicalIngredients($dish, float $scale, float $subRatio = 1.0, $rootDishId = null, bool $checkConflicts = true, $specificOrder = null): array
    {
        $components = [];
        if (!$dish || !$dish->dishIngredients) return $components;
        if (!$rootDishId) $rootDishId = $dish->id;

        foreach ($dish->dishIngredients as $di) {
            $currentK = $scale * $subRatio;
            $type = mb_strtolower(trim((string)($di->type ?? '')));
            $nettoTotalRaw = (float)($di->net_weight_g ?? 0) * $currentK;

            $conflictData = null;
            $replacementInfo = null;

            $isProduct = in_array($type, ['product', 'продукт'], true);
            $isPf = in_array($type, ['pf', 'напівфабрикат', 'п/ф', 'н/ф'], true);

            if ($checkConflicts && $specificOrder && $isProduct && $di->ingredient) {
                $ingId = (int)$di->ingredient->id;
                if ($this->effectiveExclusions($specificOrder)->contains('id', $ingId)) {
                    $rep = $specificOrder->replacements
                        ->where('dish_id', $rootDishId)
                        ->where('original_product_id', $ingId)
                        ->first();

                    if ($rep && $rep->force_approved) {
                        // Примусово одобрено — показуємо як одобрений
                        $conflictData = [
                            'is_resolved'      => true,
                            'is_force_approved' => true,
                            'replacement'      => null,
                            'original_ing_id'  => $ingId,
                            'allergen'         => null,
                        ];
                    } else {
                        if ($rep && $rep->replacementProduct) {
                            $newYield = (float)($rep->replacementProduct->yield_percent ?: 100);
                            if ($newYield <= 0) $newYield = 100;

                            $replacementInfo = [
                                'name' => $rep->replacementProduct->name,
                                'netto' => round($nettoTotalRaw, 1),
                                'brutto' => round(($nettoTotalRaw * 100) / $newYield, 1),
                                'unit' => $rep->replacementProduct->unit ?? 'г',
                                'product_id' => (int)$rep->replacementProduct->id,
                            ];
                        }

                        $conflictData = [
                            'is_resolved'       => (bool)$replacementInfo,
                            'replacement'       => $replacementInfo,
                            'original_ing_id'   => $ingId,
                            'allergen'          => $di->ingredient->allergens->pluck('name')->join(', ') ?: null,
                            'bundle_suggestion' => $replacementInfo ? null : $this->getBundleSuggestion($specificOrder, $ingId),
                        ];
                    }
                }
            }

            // 1) PRODUCT
            if ($isProduct && $di->ingredient) {
                $yield = (float)($di->ingredient->yield_percent ?: 100);
                if ($yield <= 0) $yield = 100;

                $components[] = [
                    'type' => 'product',
                    'name' => $di->ingredient->name,
                    'weight_netto' => round($nettoTotalRaw, 1),
                    'weight_brutto' => round(($nettoTotalRaw * 100) / $yield, 1),
                    'unit' => $di->ingredient->unit ?? 'г',
                    'conflict' => $conflictData,
                    'product_id' => (int)$di->ingredient->id,
                ];

                continue;
            }

            // 2) PF / CHILD DISH
            if ($isPf && $di->childDish) {
                // ✅ ВАЖНО: доля ПФ считается от ВЫХОДА ПФ (output_weight),
                // потому что net_weight_g в блюде — это "сколько ГОТОВОГО ПФ кладем".
                $pfTotals = $di->childDish->calculated_totals;
                $pfOutput = (float)($pfTotals['output_weight'] ?? 0);

                if ($pfOutput <= 0) {
                    // если ПФ некорректный — пропускаем, чтобы не ломать математику
                    continue;
                }

                $pfRatio = ((float)($di->net_weight_g ?? 0)) / $pfOutput;

                $subIngredients = $this->getHierarchicalIngredients(
                    $di->childDish,
                    $scale,
                    ($pfRatio * $subRatio),
                    $rootDishId,
                    $checkConflicts,
                    $specificOrder
                );

                $sumNetto = 0.0;
                $sumBrutto = 0.0;

                foreach ($subIngredients as $s) {
                    $sumNetto += (float)($s['weight_netto'] ?? ($s['weight_output'] ?? 0));
                    $sumBrutto += (float)($s['weight_brutto'] ?? ($s['weight_brutto_sum'] ?? 0));
                }

                $components[] = [
                    'type' => 'pf',
                    'name' => $di->childDish->name,
                    // в отчёте для ПФ показываем "сколько готового ПФ нужно"
                    'weight_output' => round($nettoTotalRaw, 1),
                    'weight_netto_sum' => round($sumNetto, 1),
                    'weight_brutto_sum' => round($sumBrutto, 1),
                    // 👇 ДОДАНО: Ключі weight_netto та weight_brutto, щоб шаблон бачив вагу!
                    'weight_netto' => round($sumNetto, 1),
                    'weight_brutto' => round($sumBrutto, 1),
                    'sub_ingredients' => $subIngredients
                ];
            }
        }

        return $components;
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

    /**
     * Якщо інгредієнт `$ingId` присутній у якомусь з прив'язаних до клієнта шаблонів —
     * повернути пропозицію заміни з цього шаблону. Лише підказка, нічого не зберігає.
     * Повертає [`name`, `product_id`, `bundle_name`] або null.
     */
    private function getBundleSuggestion($order, int $ingId): ?array
    {
        foreach (($order->client->replacementBundles ?? collect()) as $bundle) {
            foreach ($bundle->items as $item) {
                if ((int) $item->original_ingredient_id === $ingId && $item->replacementIngredient) {
                    return [
                        'name'        => $item->replacementIngredient->name,
                        'product_id'  => (int) $item->replacementIngredient->id,
                        'bundle_name' => $bundle->name,
                    ];
                }
            }
        }
        return null;
    }

    // =========================================================
    // ✅ ПЛАН РАЦИОНА
    // =========================================================


    private function plannedDishWeight(array $items, int $dishId, int $mealTypeId): ?int
    {
        foreach ($items as $it) {
            if ((int)$it['dish_id'] === $dishId && (int)$it['meal_type_id'] === $mealTypeId) {
                return (int)$it['weight'];
            }
        }
        return null;
    }
}
