<?php

namespace App\Http\Controllers;

use App\Models\DailyMenu;
use App\Models\DailyMenuDish;
use App\Models\Dish;
use App\Models\MenuPlan;
use App\Models\Order;
use App\Models\Setting;
use App\Traits\CalculatesOrderPlan;
use Illuminate\Http\Request;
use Carbon\Carbon;

class PrintController extends Controller
{
    use CalculatesOrderPlan;
    public function manifest(Request $request)
    {
        $inputDate  = $request->input('date', now()->format('Y-m-d'));
        $layout     = $request->input('layout', 'default');
        $targetDate = Carbon::parse($inputDate)->addDay()->format('Y-m-d');

        $orders = Order::whereIn('status', ['new', 'active'])
            ->whereHas('orderDays', function ($query) use ($targetDate) {
                $query->where('date', $targetDate);
            })
            ->with([
                'client.mealTypes',
                'client.dishExclusions',
                'client.ingredientExclusions',
                'client.replacementBundles.items.originalIngredient',
                'ingredientExclusions',
                'menuPlan',
                'projectData',
                'orderDays' => fn($q) => $q->where('date', $targetDate),
                'replacements.replacementDish',
                'replacements.replacementProduct',
            ])
            ->get();

        if ($orders->isEmpty()) {
            return "Немає активних замовлень на {$targetDate}.";
        }

        // Меню кешуємо по plan_id — кожен план може мати свій день циклу
        $menusByPlan = $this->getMenusForOrders($orders, $targetDate);

        $manifests = [];

        // Палітра кружечків (колір + літера) по id прийому їжі
        $mealPalette = \App\Models\MealType::all()->keyBy('id')->map(fn($mt) => [
            'color'      => $mt->color ?: '#94a3b8',
            'letter'     => $mt->short_letter ?: '?',
            'sort_order' => $mt->sort_order ?? 99,
        ])->toArray();

        foreach ($orders as $order) {
            $planId = $order->effectiveMenuPlan()?->id;
            $menu = $menusByPlan[$planId]['menu'] ?? null;
            if (!$menu) continue;

            $calc = $this->calculateOrderPlan($order, $menu, $targetDate);

            if (empty($calc['items'])) {
                continue;
            }

            // Підставляємо замінені страви + визначаємо кружечки ТОЧНО як у стикерах
            $items                = $calc['items'];
            $circles              = [];
            $addedCircleMealTypes = [];

            foreach ($items as &$item) {
                $dishId     = $item['dish_id'];
                $mealTypeId = $item['meal_type_id'];

                // Знаходимо об'єкт страви з меню (там вже завантажені dishIngredients)
                $menuItem = $menu->menuItems->first(
                    fn($mi) => (int)$mi->dish_id === $dishId && (int)$mi->meal_type_id === $mealTypeId
                );
                $dish = $menuItem?->dish;

                // Заміна страви цілком
                $dishRep = $order->replacements
                    ->where('dish_id', $dishId)
                    ->whereNull('original_product_id')
                    ->first();
                $dishForceApproved = $dishRep && $dishRep->force_approved;

                if ($dishRep && $dishRep->replacementDish) {
                    $item['original_dish']  = $item['dish'];
                    $item['dish']           = $dishRep->replacementDish->name;
                    $item['is_replacement'] = true;
                }

                // Та сама логіка що і в стикерах
                $hasChanges = false;
                if ($dishRep && $dishRep->replacementDish) {
                    $hasChanges = true;
                } elseif (!$dishForceApproved && $order->client?->dishExclusions?->contains('id', $dishId)) {
                    $hasChanges = true;
                } elseif ($dish) {
                    $hasChanges = !empty($this->findIngredientChanges($dish, $order, $dishId));
                }

                if ($hasChanges && isset($mealPalette[$mealTypeId]) && !in_array($mealTypeId, $addedCircleMealTypes)) {
                    $circles[]              = $mealPalette[$mealTypeId];
                    $addedCircleMealTypes[] = $mealTypeId;
                }
            }
            unset($item);

            // Сортуємо кружечки за порядком прийомів їжі
            usort($circles, fn($a, $b) => $a['sort_order'] <=> $b['sort_order']);

            $orderDay = $order->orderDays->first();
            $address = $orderDay?->address
                ?? $order->client?->addresses()->where('is_default', true)->first()?->address
                ?? $order->client?->address
                ?? 'Самовивіз';

            $waterOption = match($order->client?->water_option) {
                'with_water'          => 'З водою',
                'without_water'       => 'Без води',
                'water_without_lemon' => 'Вода без лимону',
                default               => null,
            };

            // Ефективний час доставки: override дня → інакше час замовлення
            $effectiveDeliveryTime = $orderDay?->delivery_time ?? $order->delivery_time ?? '';
            // Визначаємо зміну: якщо є override — дивимося на годину (>=12 = вечір)
            // Якщо override немає — використовуємо schedule_type замовлення
            if ($orderDay?->delivery_time) {
                $hour = (int) explode(':', $orderDay->delivery_time)[0];
                $isEvening = $hour >= 12;
            } else {
                $isEvening = \App\Services\ScheduleService::isEvening($order->schedule_type);
            }

            $manifests[] = [
                'client_id'    => $order->client?->id ?? '---',
                'has_cutlery'  => (bool) ($order->client?->has_cutlery ?? true),
                'water_option' => $waterOption,
                'circles'      => $circles,
                'project'      => $order->project,
                'client'       => $order->client?->name ?? 'Без імені',
                'address'      => $address,
                'calories'     => (int) $order->calories,
                'comment'      => $order->client?->production_comment,
                'items'        => $items,
                'date'         => $targetDate,
                'menu_token'   => $order->menu_token,
                'is_evening'   => $isEvening,
                'nutrition'    => [
                    'b' => round($calc['totals']['prot']),
                    'j' => round($calc['totals']['fat']),
                    'u' => round($calc['totals']['carb']),
                ],
            ];
        }

        usort($manifests, function ($a, $b) {
            // 1. За проєктом
            $projectCmp = strcmp($a['project'] ?? '', $b['project'] ?? '');
            if ($projectCmp !== 0) return $projectCmp;
            // 2. За калоріями
            return $a['calories'] <=> $b['calories'];
        });

        // 🔥 ВИПРАВЛЕННЯ: Передаємо базову дату, щоб уникнути "+2 дні" в шаблоні
        $date = $inputDate; 
        return view('print.manifest', compact('manifests', 'date', 'layout'));
    }

    public function miniManifest(Request $request)
    {
        $inputDate  = $request->input('date', now()->format('Y-m-d'));
        $targetDate = Carbon::parse($inputDate)->addDay()->format('Y-m-d');

        $orders = Order::whereIn('status', ['new', 'active'])
            ->whereHas('orderDays', function ($query) use ($targetDate) {
                $query->where('date', $targetDate);
            })
            ->with([
                'client.dishExclusions',
                'client.ingredientExclusions',
                'client.replacementBundles.items.originalIngredient',
                'ingredientExclusions',
                'menuPlan',
                'projectData',
                'replacements.replacementProduct',
                'replacements.replacementDish',
                'orderDays' => fn($q) => $q->where('date', $targetDate),
            ])
            ->get();

        // Меню кешуємо по plan_id — кожен план має свій день циклу
        $menusByPlan = $this->getMenusForOrders($orders, $targetDate);

        // Палітра кружечків по id прийому їжі
        $mealPalette = \App\Models\MealType::all()->keyBy('id')->map(fn($mt) => [
            'color'      => $mt->color ?: '#94a3b8',
            'letter'     => $mt->short_letter ?: '?',
            'sort_order' => $mt->sort_order ?? 99,
        ])->toArray();

        $manifests = [];

        foreach ($orders as $order) {
            $orderDay = $order->orderDays->first();
            $address = $orderDay?->address
                ?? $order->client?->addresses()->where('is_default', true)->first()?->address
                ?? $order->client?->address
                ?? 'Самовивіз';

            // Ефективний час: override дня → інакше schedule_type замовлення
            if ($orderDay?->delivery_time) {
                $hour = (int) explode(':', $orderDay->delivery_time)[0];
                $isEvening = $hour >= 12;
            } else {
                $isEvening = \App\Services\ScheduleService::isEvening($order->schedule_type);
            }

            // Будуємо кружечки замін
            $circles = [];
            $addedCircleMealTypes = [];

            $menu = $menusByPlan[$order->effectiveMenuPlan()?->id]['menu'] ?? null;
            if ($menu) {
                $calc = $this->calculateOrderPlan($order, $menu, $targetDate);
                foreach ($calc['items'] as $item) {
                    $dishId     = $item['dish_id'] ?? null;
                    $mealTypeId = $item['meal_type_id'] ?? null;
                    if (!$dishId) continue;

                    $menuItem = $menu->menuItems->first(
                        fn($mi) => (int)$mi->dish_id === $dishId && (int)$mi->meal_type_id === $mealTypeId
                    );
                    $dish = $menuItem?->dish;

                    $dishRep = $order->replacements
                        ->where('dish_id', $dishId)
                        ->whereNull('original_product_id')
                        ->first();
                    $dishForceApproved = $dishRep && $dishRep->force_approved;

                    $hasChanges = false;
                    if ($dishRep && $dishRep->replacementDish) {
                        $hasChanges = true;
                    } elseif (!$dishForceApproved && $order->client?->dishExclusions?->contains('id', $dishId)) {
                        $hasChanges = true;
                    } elseif ($dish) {
                        $hasChanges = !empty($this->findIngredientChanges($dish, $order, $dishId));
                    }

                    if ($hasChanges && isset($mealPalette[$mealTypeId]) && !in_array($mealTypeId, $addedCircleMealTypes)) {
                        $circles[]              = $mealPalette[$mealTypeId];
                        $addedCircleMealTypes[] = $mealTypeId;
                    }
                }
                usort($circles, fn($a, $b) => $a['sort_order'] <=> $b['sort_order']);
            }

            $manifests[] = [
                'client_id'          => $order->client?->id ?? '---',
                'project'            => $order->project,
                'client'             => $order->client?->name ?? 'Без імені',
                'address'            => $address,
                'calories'           => (int) $order->calories,
                'is_evening'         => $isEvening,
                'is_individual'      => $order->menu_type === 'individual',
                'delivery_slot'      => $isEvening ? 'Вечір' : 'Ранок',
                'menu_token'         => $order->menu_token,
                'ant_route_num'      => $orderDay?->ant_route_num,
                'ant_route_pos'      => $orderDay?->ant_route_pos,
                'ant_driver'         => $orderDay?->ant_driver,
                'ant_delivery_group' => $orderDay?->ant_delivery_group,
                'circles'            => $circles,
                'has_cutlery'        => (bool) ($order->client?->has_cutlery ?? true),
                'water_option'       => $order->client?->water_option,
                'bundles'            => $order->client?->replacementBundles?->pluck('name')->values()->all() ?? [],
            ];
        }

        usort($manifests, function ($a, $b) {
            // 1. Індивідуальні — спочатку
            if ($a['is_individual'] !== $b['is_individual']) {
                return $a['is_individual'] ? -1 : 1;
            }
            // 2. Спочатку ранок, потім вечір
            if ($a['is_evening'] !== $b['is_evening']) {
                return $a['is_evening'] ? 1 : -1;
            }
            // 3. Всередині — за проєктом
            $projectCmp = strcmp($a['project'] ?? '', $b['project'] ?? '');
            if ($projectCmp !== 0) {
                return $projectCmp;
            }
            // 4. Всередині проєкту — за калоріями
            return $a['calories'] <=> $b['calories'];
        });

        // 🔥 ВИПРАВЛЕННЯ: Передаємо базову дату
        $date = $inputDate; 
        return view('print.mini-manifest', compact('manifests', 'date'));
    }

    public function stickers(Request $request)
    {
        $inputDate  = $request->input('date', now()->format('Y-m-d'));
        $targetDate = Carbon::parse($inputDate)->addDay()->format('Y-m-d');

        $orders = Order::whereIn('status', ['new', 'active'])
            ->whereHas('orderDays', function ($query) use ($targetDate) {
                $query->where('date', $targetDate);
            })
            ->with([
                'client.mealTypes',
                'client.ingredientExclusions',
                'client.dishExclusions',
                'client.replacementBundles.items.originalIngredient',
                'ingredientExclusions',
                'menuPlan',
                'projectData',
                'replacements.replacementProduct',
                'replacements.replacementDish',
                'orderDays' => fn($q) => $q->where('date', $targetDate),
            ])
            ->get();

        if ($orders->isEmpty()) {
            return "Немає активних замовлень на завтра ({$targetDate}).";
        }

        // Меню кешуємо по plan_id — кожен план має свій день циклу
        $menusByPlan = $this->getMenusForOrders($orders, $targetDate);

        $stickers = [];

        foreach ($orders as $order) {
            $menu = $menusByPlan[$order->effectiveMenuPlan()?->id]['menu'] ?? null;
            if (!$menu) continue;

            $calc = $this->calculateOrderPlan($order, $menu, $targetDate);

            if (empty($calc['items'])) {
                continue;
            }

            // Зміна доставки: ранок чи вечір (override з дня → інакше schedule_type)
            $orderDay = $order->orderDays->first();
            if ($orderDay?->delivery_time) {
                $hour = (int) explode(':', $orderDay->delivery_time)[0];
                $isEvening = $hour >= 12;
            } else {
                $isEvening = \App\Services\ScheduleService::isEvening($order->schedule_type);
            }

            foreach ($calc['items'] as $it) {
                    $dishId     = $it['dish_id'] ?? null;
                    $mealTypeId = $it['meal_type_id'] ?? null;
                    if (!$dishId) continue;

                    $menuItem = $menu->menuItems->first(function ($mi) use ($dishId, $mealTypeId) {
                        return (int)$mi->dish_id === (int)$dishId && (int)$mi->meal_type_id === (int)$mealTypeId;
                    });
                    if (!$menuItem || !$menuItem->dish) continue;

                    $dish = $menuItem->dish;

                    $changes = []; 

                    $dishRep = $order->replacements->where('dish_id', $dish->id)->whereNull('original_product_id')->first();
                    $dishForceApproved = $dishRep && $dishRep->force_approved;
                    if ($dishRep && $dishRep->replacementDish) {
                        $changes[] = "ЗАМІНА СТРАВИ: " . $dishRep->replacementDish->name;
                    } elseif (!$dishForceApproved && $order->client->dishExclusions->contains('id', $dish->id)) {
                        $changes[] = "НЕ ЇСТЬ ЦЮ СТРАВУ";
                    } else {
                        $ingredientChanges = $this->findIngredientChanges($dish, $order, $dish->id);
                        $changes = array_merge($changes, $ingredientChanges);
                    }

                if (!empty($changes)) {
                    $stickers[] = [
                        'client'          => $order->client?->name ?? 'Без імені',
                        'client_id'       => $order->client?->id ?? '---',
                        'meal'            => $menuItem->mealType?->name ?? 'Прийом',
                        'meal_type_id'    => $menuItem->mealType?->id,
                        'meal_sort_order' => $menuItem->mealType?->sort_order ?? 99,
                        'dish'            => $dish->name,
                        'weight'          => (int) $it['weight'],
                        'time'            => $menuItem->mealType?->sort_order ?? 99,
                        'calories'        => (int) $order->calories,
                        'project'         => $order->project,
                        'changes'         => $changes,
                        'date'            => $targetDate,
                        'bundles'         => $order->client?->replacementBundles?->pluck('name')->values()->all() ?? [],
                        'is_evening'      => $isEvening,
                        'delivery_slot'   => $isEvening ? 'Вечір' : 'Ранок',
                    ];
                }
            }
        }

        // Завантажуємо кольори та літери прямо з БД
        $mealPalette = \App\Models\MealType::all()->keyBy('sort_order')->map(fn ($mt) => [
            'color'  => $mt->color ?: '#94a3b8',
            'letter' => $mt->short_letter ?: '?',
        ])->toArray();

        // Збираємо унікальні sort_order прийомів їжі із замінами для кожного клієнта
        $clientMealSortOrders = [];
        foreach ($stickers as $s) {
            $cid = $s['client_id'];
            $so  = $s['meal_sort_order'];
            if ($so && !in_array($so, $clientMealSortOrders[$cid] ?? [], true)) {
                $clientMealSortOrders[$cid][] = $so;
            }
        }

        // Додаємо кружечки до кожного стікера
        foreach ($stickers as &$s) {
            $circles = [];
            $sortOrders = $clientMealSortOrders[$s['client_id']] ?? [];
            sort($sortOrders);
            foreach ($sortOrders as $so) {
                $circles[] = $mealPalette[$so] ?? ['color' => '#94a3b8', 'letter' => '?'];
            }
            $s['circles'] = $circles;
        }
        unset($s);

        usort($stickers, function ($a, $b) {
            // 1. За порядком прийому їжі
            $timeCmp = $a['time'] <=> $b['time'];
            if ($timeCmp !== 0) return $timeCmp;
            // 2. За проєктом
            $projectCmp = strcmp($a['project'] ?? '', $b['project'] ?? '');
            if ($projectCmp !== 0) return $projectCmp;
            // 3. За калоріями
            return $a['calories'] <=> $b['calories'];
        });

        // 🔥 ВИПРАВЛЕННЯ: Передаємо базову дату
        $date = $inputDate; 
        return view('print.stickers', compact('stickers', 'date'));
    }

    public function packagingList(Request $request)
    {
        $inputDate  = $request->input('date', now()->format('Y-m-d'));
        $targetDate = Carbon::parse($inputDate)->addDay()->format('Y-m-d');

        $orders = Order::whereIn('status', ['new', 'active'])
            ->whereHas('orderDays', function ($query) use ($targetDate) {
                $query->where('date', $targetDate);
            })
            ->with([
                'client.mealTypes',
                'client.ingredientExclusions',
                'client.dishExclusions',
                'client.replacementBundles.items.originalIngredient',
                'ingredientExclusions',
                'menuPlan',
                'replacements.replacementProduct',
                'replacements.replacementDish',
                'replacements.originalProduct',
                'projectData',
            ])
            ->get();

        if ($orders->isEmpty()) {
            return "Немає активних замовлень на {$targetDate}.";
        }

        // Групуємо по планах меню — кожен план має свої страви/день циклу
        $ordersByPlan = $orders->groupBy(fn ($o) => $o->effectiveMenuPlan()?->id ?? 0);

        $reportByPlan = []; // [planId => ['plan'=>MenuPlan, 'day_number'=>int, 'report'=>[...]]]
        $missingPlans = [];

        foreach ($ordersByPlan as $planId => $planOrders) {
            $plan = $planOrders->first()->effectiveMenuPlan();
            if (!$plan) continue;

            $dayNumber = $plan->globalDayFor($targetDate);
            $menu = DailyMenu::where('menu_plan_id', $plan->id)
                ->where('day_number', $dayNumber)
                ->with([
                    'menuItems.dish.dishIngredients.ingredient',
                    'menuItems.dish.dishIngredients.childDish',
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

            $report = [];
            $sortedMenuItems = $menu->menuItems->sortBy(fn($item) => $item->mealType?->sort_order ?? 99);
            $orders = $planOrders; // alias для існуючого нижче коду

        foreach ($sortedMenuItems as $mItem) {
            $dish = $mItem->dish;
            if (!$dish) continue;

            $tableData = [
                'meal' => $mItem->mealType?->name ?? '-',
                'dish_name' => $dish->name,
                'columns' => [],
                'rows' => [],
                'individual_notes' => [],
            ];

            foreach ($orders as $order) {
                if ($order->menu_type === 'individual') continue; // індивідуальні — окремо

                $calc = $this->calculateOrderPlan($order, $menu, $targetDate);

                $plannedDish = collect($calc['items'])->first(function ($it) use ($dish, $mItem) {
                    return (int)$it['dish_id'] === (int)$dish->id && (int)$it['meal_type_id'] === (int)$mItem->meal_type_id;
                });

                if (!$plannedDish) continue;

                $baseW = (float)($dish->base_weight_g ?? 0);
                $realW = (float)($plannedDish['weight'] ?? 0);
                $dishScale = ($baseW > 0) ? ($realW / $baseW) : 0.0;

                $clientMeta = [
                    'id'           => $order->client->id,
                    'name'         => $order->client->name,
                    'project'      => $order->projectData?->name ?? ucfirst($order->project ?? ''),
                    'project_slug' => $order->project ?? 'none',
                    'calories'     => (int)($order->calories ?? 0),
                ];

                // 1. Виключення/заміна цілої страви
                if ($order->client->dishExclusions->contains('id', $dish->id)) {
                    $dishRep = $order->replacements->where('dish_id', $dish->id)->whereNull('original_product_id')->first();
                    if ($dishRep && $dishRep->replacementDish) {
                        $tableData['individual_notes'][] = array_merge($clientMeta, ['text' => "Страву замінено на «{$dishRep->replacementDish->name}»"]);
                    } else {
                        $tableData['individual_notes'][] = array_merge($clientMeta, ['text' => "Страву повністю ВИКЛЮЧЕНО"]);
                    }
                    // Якщо страва виключена — інгредієнти не перевіряємо
                    goto next_order_packaging;
                }

                // 3. Заміни інгредієнтів + конфлікти (з урахуванням force_approved)
                {
                    $noteParts = [];
                    $this->collectIngredientNoteParts($dish, $order, $dish->id, $noteParts);
                    if (!empty($noteParts)) {
                        $tableData['individual_notes'][] = array_merge($clientMeta, ['text' => implode(', ', $noteParts)]);
                    }
                }

                next_order_packaging:

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

            if (empty($tableData['columns'])) continue;

            ksort($tableData['columns']);

            foreach ($dish->dishIngredients as $di) {
                $originalName = $di->ingredient
                    ? $di->ingredient->name
                    : ($di->childDish ? "[НФ] " . $di->childDish->name : '???');

                $cells = [];
                foreach ($tableData['columns'] as $key => $col) {
                    $count = (int)($col['count'] ?? 1);
                    $sumScale = (float)($col['sum_scale'] ?? 0.0);
                    
                    $onePortionScale = $count > 0 ? ($sumScale / $count) : 0;

                    $cells[$key] = [
                        'val' => round(((float)($di->net_weight_g ?? 0)) * $onePortionScale),
                    ];
                }

                $tableData['rows'][] = [
                    'original_name' => $originalName,
                    'cells' => $cells,
                ];
            }

            $report[] = $tableData;
        }

        // === ІНДИВІДУАЛЬНІ КЛІЄНТИ — одна картка на клієнта ===
        $individualByOrder = [];

        foreach ($orders as $order) {
            if ($order->menu_type !== 'individual') continue;

            $calc = $this->calculateOrderPlan($order, $menu, $targetDate);
            if (empty($calc['items'])) continue;

            $oid = $order->id;
            if (!isset($individualByOrder[$oid])) {
                $individualByOrder[$oid] = [
                    'is_individual' => true,
                    'client_label'  => '#' . $order->client->id . ' ' . $order->client->name,
                    'calories'      => (int)($order->calories ?? 0),
                    'project'       => $order->projectData?->name ?? ucfirst($order->project ?? ''),
                    'meals'         => [],
                ];
            }

            foreach ($calc['items'] as $item) {
                $dish = \App\Models\Dish::with('dishIngredients.ingredient', 'dishIngredients.childDish')
                    ->find($item['dish_id']);
                if (!$dish) continue;

                $weight = (int)$item['weight'];
                $baseW  = (float)($dish->base_weight_g ?? 0);
                $scale  = $baseW > 0 ? $weight / $baseW : 0.0;

                $rows = [];
                foreach ($dish->dishIngredients as $di) {
                    $name = $di->ingredient
                        ? $di->ingredient->name
                        : ($di->childDish ? '[НФ] ' . $di->childDish->name : '???');
                    $val  = round((float)($di->net_weight_g ?? 0) * $scale);
                    if ($val > 0) $rows[] = ['name' => $name, 'weight' => $val];
                }

                $individualByOrder[$oid]['meals'][] = [
                    'meal'      => $item['meal'],
                    'dish_name' => $dish->name,
                    'rows'      => $rows,
                ];
            }
        }

        foreach ($individualByOrder as $clientData) {
            $report[] = $clientData;
        }

            if (!empty($report)) {
                $reportByPlan[$plan->id] = [
                    'plan'       => $plan,
                    'day_number' => $dayNumber,
                    'report'     => $report,
                ];
            }
        } // кінець foreach $ordersByPlan

        if (empty($reportByPlan) && empty($missingPlans)) {
            return "Меню не знайдено на завтра ({$targetDate})";
        }

        // Глобальні коментарі клієнтів — один блок зверху, дедуп по client_id
        $clientComments = [];
        $seenClients = [];
        foreach ($orders as $order) {
            $cid = (int) $order->client?->id;
            if (!$cid || isset($seenClients[$cid])) continue;
            $comment = trim($order->client->production_comment ?? '');
            if (!empty($comment)) {
                $clientComments[] = [
                    'id'      => $cid,
                    'name'    => $order->client->name,
                    'project' => $order->projectData?->name ?? ucfirst($order->project ?? ''),
                    'calories'=> (int)($order->calories ?? 0),
                    'text'    => $comment,
                ];
                $seenClients[$cid] = true;
            }
        }

        $date = $inputDate;
        return view('print.packaging-list', [
            'reportByPlan'   => $reportByPlan,
            'missingPlans'   => $missingPlans,
            'date'           => $date,
            'clientComments' => $clientComments,
        ]);
    }

    public function productionReport(Request $request)
    {
        $inputDate = $request->input('date', now()->format('Y-m-d'));
        $targetDate = Carbon::parse($inputDate)->addDay()->format('Y-m-d');

        $orders = Order::whereIn('status', ['new', 'active'])
            ->whereHas('orderDays', function ($query) use ($targetDate) {
                $query->where('date', $targetDate);
            })
            ->with([
                'client.mealTypes',
                'client.ingredientExclusions',
                'client.dishExclusions',
                'client.replacementBundles.items.originalIngredient',
                'ingredientExclusions',
                'menuPlan',
                'replacements.replacementProduct',
                'replacements.replacementDish.dishIngredients.ingredient',
                'projectData',
            ])
            ->get();

        if ($orders->isEmpty()) {
            return "Немає активних замовлень на {$targetDate}.";
        }

        // === ГРУПУЄМО ЗАМОВЛЕННЯ ПО ПЛАНАХ МЕНЮ ===
        $ordersByPlan = $orders->groupBy(fn ($o) => $o->effectiveMenuPlan()?->id ?? 0);

        $report = []; // [$planId => ['plan' => MenuPlan, 'day_number' => int, 'meals' => [...], 'individuals' => [...]]]
        $missingPlans = [];

        foreach ($ordersByPlan as $planId => $planOrders) {
            $plan = $planOrders->first()->effectiveMenuPlan();
            if (!$plan) continue;

            $dayNumber = $plan->globalDayFor($targetDate);

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

            $orderPlans = [];
            foreach ($planOrders as $order) {
                $orderPlans[$order->id] = $this->calculateOrderPlan($order, $menu, $targetDate);
            }

            $planMeals = [];
            $sortedMenuItems = $menu->menuItems->sortBy(fn($item) => $item->mealType?->sort_order ?? 99);

            foreach ($sortedMenuItems as $item) {
                if (!$item->dish) continue;

                $mealName = $item->mealType->name ?? 'Інше';
                $dish = $item->dish;

                $standard = [];
                $custom = [];
                $commentClients = [];

                foreach ($planOrders as $order) {
                    if ($order->menu_type === 'individual') continue;

                    $orderPlan = $orderPlans[$order->id] ?? null;
                    if (!$orderPlan) continue;

                    $plannedWeight = collect($orderPlan['items'])->first(function ($it) use ($dish, $item) {
                        return (int)$it['dish_id'] === (int)$dish->id && (int)$it['meal_type_id'] === (int)$item->meal_type_id;
                    })['weight'] ?? null;

                    if ($plannedWeight === null) continue;

                    $baseW = (float)($dish->base_weight_g ?? 0);
                    $dishScale = ($baseW > 0) ? ((float)$plannedWeight / $baseW) : 0.0;

                    if (!empty(trim($order->client->production_comment ?? ''))) {
                        $commentClients[] = [
                            'client_name' => $order->client->name,
                            'order_id'    => $order->id,
                            'comment'     => trim($order->client->production_comment),
                        ];
                    }

                    $dishForcedApprovalCheck = $order->replacements
                        ->where('dish_id', $dish->id)
                        ->whereNull('original_product_id')
                        ->where('force_approved', true)
                        ->first();

                    $hasDishLevelChange =
                        (!$dishForcedApprovalCheck && $order->client->dishExclusions->contains('id', $dish->id))
                        || $order->replacements->where('dish_id', $dish->id)->whereNull('original_product_id')->where('force_approved', false)->isNotEmpty();

                    $hasIngredientLevelChange =
                        $order->replacements->where('dish_id', $dish->id)->whereNotNull('original_product_id')->isNotEmpty()
                        || !empty($this->getConflictingIngredients($dish, $order->effectiveExcludedIngredients()));

                    $isCustom = $hasDishLevelChange || $hasIngredientLevelChange;

                    if ($isCustom) {
                        $custom[] = ['order' => $order, 'scale' => $dishScale];
                        if (!$hasDishLevelChange) {
                            $standard[] = ['order' => $order, 'scale' => $dishScale];
                        }
                    } else {
                        $standard[] = ['order' => $order, 'scale' => $dishScale];
                    }
                }

                if (empty($standard) && empty($custom)) continue;

                $standardScales = array_map(fn($x) => (float)$x['scale'], $standard);
                $standardStructure = $this->calculateIngredientsStructureByScales($dish, $standardScales);
                $standardTotals = $this->calculateStructureTotals($standardStructure);

                $customCards = collect($custom)->map(function ($entry) use ($dish) {
                    return $this->buildCustomCard($dish, $entry['order'], (float)$entry['scale']);
                })->toArray();

                $planMeals[$mealName][] = [
                    'meal_name' => $mealName,
                    'dish_id' => $dish->id,
                    'dish_name' => $dish->name,
                    'recipe' => $dish->description,
                    'standard_count' => count($standard),
                    'standard_structure' => $standardStructure,
                    'standard_total_netto' => $standardTotals['netto'],
                    'standard_total_brutto' => $standardTotals['brutto'],
                    'custom_cards' => $customCards,
                    'comment_clients' => $commentClients,
                ];
            }

            // === ІНДИВІДУАЛЬНІ КЛІЄНТИ цього плану ===
            $planIndividuals = [];
            foreach ($planOrders as $order) {
                if ($order->menu_type !== 'individual') continue;

                $orderPlan = $orderPlans[$order->id] ?? null;
                if (!$orderPlan || empty($orderPlan['items'])) continue;

                $oid = $order->id;
                $meals = [];

                foreach ($orderPlan['items'] as $item) {
                    $dish = \App\Models\Dish::with(
                        'dishIngredients.ingredient',
                        'dishIngredients.childDish.dishIngredients.ingredient'
                    )->find($item['dish_id']);
                    if (!$dish) continue;

                    $weight = (int)$item['weight'];
                    $baseW  = (float)($dish->base_weight_g ?? 0);
                    $scale  = $baseW > 0 ? $weight / $baseW : 0.0;

                    $components = $this->getHierarchicalIngredients($dish, $scale, 1.0, null, false, null);
                    $totals     = $this->calculateStructureTotals($components);

                    $meals[] = [
                        'meal'         => $item['meal'],
                        'dish_name'    => $dish->name,
                        'recipe'       => $dish->description,
                        'components'   => $components,
                        'total_netto'  => $totals['netto'],
                        'total_brutto' => $totals['brutto'],
                    ];
                }

                $planIndividuals[$oid] = [
                    'client_label' => '#' . $order->client->id . ' ' . $order->client->name,
                    'calories'     => (int)($order->calories ?? 0),
                    'project'      => $order->projectData?->name ?? ucfirst($order->project ?? ''),
                    'meals'        => $meals,
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

        if (empty($report) && empty($missingPlans)) {
            return "Меню не знайдено на завтра ({$targetDate})";
        }

        return view('print.production-report', [
            'report'             => $report,
            'missingPlans'       => $missingPlans,
            'date'               => Carbon::parse($inputDate)->format('d.m.Y'),
            'targetDateFormatted' => Carbon::parse($targetDate)->format('d.m.Y'),
            'targetDate'         => $targetDate,
        ]);
    }

    public function shoppingList(Request $request)
    {
        $date = $request->input('date', now()->addDay()->format('Y-m-d'));

        $shoppingList = [];
        $planSummaries = []; // [planId => ['plan' => MenuPlan, 'day_number' => int]]

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
            ])->get();

        $missingPlans = [];

        if ($orders->isNotEmpty()) {
            $bruttoByIng = []; // глобальний агрегат: купуємо одну загальну купу для всіх планів

            // Групуємо замовлення по планах меню
            $ordersByPlan = $orders->groupBy(fn ($o) => $o->effectiveMenuPlan()?->id ?? 0);

            foreach ($ordersByPlan as $planId => $planOrders) {
                $plan = $planOrders->first()->effectiveMenuPlan();
                if (!$plan) continue;

                $dayNumber = $plan->globalDayFor($date);
                $menu = DailyMenu::where('menu_plan_id', $plan->id)
                    ->where('day_number', $dayNumber)
                    ->with([
                        'menuItems.dish.dishIngredients.ingredient',
                        'menuItems.dish.dishIngredients.childDish.dishIngredients.ingredient',
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

                $planSummaries[$plan->id] = ['plan' => $plan, 'day_number' => $dayNumber];

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

                        $dishForceApproved = $dishReplacement && $dishReplacement->force_approved;
                        if ($order->client->dishExclusions->contains('id', $dish->id) && !$dishReplacement && !$dishForceApproved) continue;

                        $this->collectBruttoForShopping($activeDish, $dishScale, 1.0, $order, (int)$dish->id, $bruttoByIng);
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

                        $this->collectBruttoForShopping($dish, $scale, 1.0, null, (int)$dish->id, $bruttoByIng);
                    }
                }
            }

            foreach ($bruttoByIng as $id => $info) {
                $dbIng = \App\Models\Ingredient::find($id);
                if (!$dbIng) continue;

                $stock  = (float)($dbIng->stock ?? 0);
                $unit   = $info['unit'];
                $bruttoInUnit = in_array($unit, ['кг', 'л', 'kg', 'l']) ? $info['brutto_g'] / 1000.0 : $info['brutto_g'];
                $toBuy  = max(0.0, $bruttoInUnit - $stock);

                $shoppingList[] = [
                    'name'   => $info['name'],
                    'need'   => $bruttoInUnit,
                    'stock'  => $stock,
                    'to_buy' => $toBuy,
                    'unit'   => $unit,
                    'enough' => $toBuy <= 0,
                ];
            }
            usort($shoppingList, fn ($a, $b) => strcmp($a['name'], $b['name']));
        }

        return view('print.shopping-list', [
            'shoppingList'  => $shoppingList,
            'date'          => Carbon::parse($date)->format('d.m.Y'),
            'planSummaries' => $planSummaries, // для шапки: «План X — день N»
            'missingPlans'  => $missingPlans,
        ]);
    }

    private function collectBruttoForShopping($dish, float $scale, float $subRatio, $order, int $rootDishId, array &$acc): void
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
                $rep = $order?->replacements
                    ->where('dish_id', $rootDishId)
                    ->where('original_product_id', $ing->id)->first();
                if ($rep?->replacementProduct) $ing = $rep->replacementProduct;

                $isExcluded = $order?->effectiveExcludedIngredients()->contains('id', $di->ingredient->id);
                if ($isExcluded && !$rep?->replacementProduct && !$rep?->force_approved) continue;

                $yield   = (float)($ing->yield_percent ?: 100);
                $bruttoG = ($netG * 100) / max($yield, 1);

                if (!isset($acc[$ing->id])) {
                    $acc[$ing->id] = ['name' => $ing->name, 'brutto_g' => 0.0, 'unit' => $ing->unit ?? 'г'];
                }
                $acc[$ing->id]['brutto_g'] += $bruttoG;
            }

            if ($isPf && $di->childDish) {
                $pfOutput = (float)(($di->childDish->calculated_totals)['output_weight'] ?? 0);
                if ($pfOutput <= 0) continue;
                $pfRatio = ((float)($di->net_weight_g ?? 0)) / $pfOutput;
                $this->collectBruttoForShopping($di->childDish, $scale, $pfRatio * $subRatio, $order, $rootDishId, $acc);
            }
        }
    }

    public function stockList(Request $request)
    {
        $inputDate = $request->input('date', now()->format('Y-m-d'));
        $targetDate = Carbon::parse($inputDate)->addDay()->format('Y-m-d');

        $orders = Order::whereIn('status', ['new', 'active'])
            ->whereHas('orderDays', function ($query) use ($targetDate) {
                $query->where('date', $targetDate);
            })
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
            return "Немає активних замовлень на {$targetDate}.";
        }

        $summaryIngredients = [];

        $collectSummary = function($components) use (&$summaryIngredients, &$collectSummary) {
            foreach ($components as $comp) {
                if (($comp['type'] ?? '') === 'product') {
                    $name = $comp['name'];
                    $summaryIngredients[$name] = ($summaryIngredients[$name] ?? 0) + ($comp['weight_brutto'] ?? 0);
                } elseif (($comp['type'] ?? '') === 'pf' && isset($comp['sub_ingredients'])) {
                    $collectSummary($comp['sub_ingredients']);
                }
            }
        };

        // Групуємо по планах і збираємо все в один список (списуємо разом)
        $ordersByPlan = $orders->groupBy(fn ($o) => $o->effectiveMenuPlan()?->id ?? 0);
        $planSummaries = [];
        $missingPlans = [];

        foreach ($ordersByPlan as $planId => $planOrders) {
            $plan = $planOrders->first()->effectiveMenuPlan();
            if (!$plan) continue;

            $dayNumber = $plan->globalDayFor($targetDate);
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

            $planSummaries[$plan->id] = ['plan' => $plan, 'day_number' => $dayNumber];

            $orderPlans = [];
            foreach ($planOrders as $order) {
                $orderPlans[$order->id] = $this->calculateOrderPlan($order, $menu, $targetDate);
            }

            $sortedMenuItems = $menu->menuItems->sortBy(fn($item) => $item->mealType?->sort_order ?? 99);

            foreach ($sortedMenuItems as $item) {
                if (!$item->dish) continue;

                $dish = $item->dish;
                $standard = [];
                $custom = [];

                foreach ($planOrders as $order) {
                    if ($order->menu_type === 'individual') continue;

                    $orderPlan = $orderPlans[$order->id] ?? null;
                    if (!$orderPlan) continue;

                    $plannedWeight = collect($orderPlan['items'])->first(function ($it) use ($dish, $item) {
                        return (int)$it['dish_id'] === (int)$dish->id && (int)$it['meal_type_id'] === (int)$item->meal_type_id;
                    })['weight'] ?? null;

                    if ($plannedWeight === null) continue;

                    $baseW = (float)($dish->base_weight_g ?? 0);
                    $dishScale = ($baseW > 0) ? ((float)$plannedWeight / $baseW) : 0.0;

                    $isCustom =
                        $order->replacements->where('dish_id', $dish->id)->isNotEmpty()
                        || $order->client->dishExclusions->contains('id', $dish->id)
                        || !empty($this->getConflictingIngredients($dish, $order->effectiveExcludedIngredients()));

                    if ($isCustom) {
                        $custom[] = ['order' => $order, 'scale' => $dishScale];
                    } else {
                        $standard[] = ['order' => $order, 'scale' => $dishScale];
                    }
                }

                $standardScales = array_map(fn($x) => (float)$x['scale'], $standard);
                $standardStructure = $this->calculateIngredientsStructureByScales($dish, $standardScales);
                $collectSummary($standardStructure);

                foreach ($custom as $entry) {
                    $card = $this->buildCustomCard($dish, $entry['order'], (float)$entry['scale']);
                    if (!$card['dish_excluded'] || isset($card['dish_replacement'])) {
                        $collectSummary($card['components']);
                    }
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

                    $components = $this->getHierarchicalIngredients($dish, $scale, 1.0, null, false, null);
                    $collectSummary($components);
                }
            }
        }

        ksort($summaryIngredients);

        return view('print.stock-list', [
            'ingredients'         => $summaryIngredients,
            'date'                => Carbon::parse($inputDate)->format('d.m.Y'),
            'targetDateFormatted' => Carbon::parse($targetDate)->format('d.m.Y'),
            'planSummaries'       => $planSummaries,
            'missingPlans'        => $missingPlans,
        ]);
    }

    public function logistics(Request $request)
    {
        // Отримуємо дату (якщо немає - беремо сьогодні)
        $date = $request->input('date', now()->format('Y-m-d'));
        
        // Отримуємо зміну (morning або evening). По замовчуванню - ранок
        $shift = $request->input('shift', 'morning');

        // Формуємо красиву назву файлу з датою ДОСТАВКИ (не фасування)
        $deliveryDate = $shift === 'morning'
            ? \Carbon\Carbon::parse($date)->addDay()->format('Y-m-d')
            : $date;
        $fileName = "logistics_{$shift}_{$deliveryDate}.xlsx";

        // Передаємо ДВА параметри в наш оновлений LogisticsExport
        return \Maatwebsite\Excel\Facades\Excel::download(new \App\Exports\LogisticsExport($date, $shift), $fileName);
    }


    public function assemblySheet(Request $request)
    {
        $inputDate  = $request->input('date', now()->format('Y-m-d'));
        $targetDate = Carbon::parse($inputDate)->addDay()->format('Y-m-d');

        $orders = Order::whereIn('status', ['new', 'active'])
            ->whereHas('orderDays', fn($q) => $q->where('date', $targetDate))
            ->with([
                'client.dishExclusions',
                'client.ingredientExclusions',
                'client.replacementBundles.items.originalIngredient',
                'ingredientExclusions',
                'menuPlan',
                'orderDays' => fn($q) => $q->where('date', $targetDate),
                'replacements.replacementDish',
                'replacements.replacementProduct',
            ])
            ->get();

        // Меню кешуємо по plan_id — кожен план має свій день циклу
        $menusByPlan = $this->getMenusForOrders($orders, $targetDate);

        $rows = [];

        foreach ($orders as $order) {
            $orderDay = $order->orderDays->first();

            // Визначаємо зміну (ранок/вечір)
            if ($orderDay?->delivery_time) {
                $hour      = (int) explode(':', $orderDay->delivery_time)[0];
                $isEvening = $hour >= 12;
            } else {
                $isEvening = \App\Services\ScheduleService::isEvening($order->schedule_type);
            }

            // Формуємо текст змін для цього замовлення
            $changeParts = [];

            $menu = $menusByPlan[$order->effectiveMenuPlan()?->id]['menu'] ?? null;
            if ($menu) {
                foreach ($menu->menuItems as $menuItem) {
                    if (!$menuItem->dish) continue;
                    $dish   = $menuItem->dish;
                    $dishId = $dish->id;

                    // Повна заміна страви
                    $dishRep = $order->replacements
                        ->where('dish_id', $dishId)
                        ->whereNull('original_product_id')
                        ->where('force_approved', false)
                        ->first();

                    if ($dishRep && $dishRep->replacementDish) {
                        $changeParts[] = '→ ' . $dishRep->replacementDish->name;
                        continue;
                    }

                    // Виключення страви
                    $dishForceApproved = $order->replacements
                        ->where('dish_id', $dishId)
                        ->whereNull('original_product_id')
                        ->where('force_approved', true)
                        ->isNotEmpty();

                    if (!$dishForceApproved && $order->client->dishExclusions->contains('id', $dishId)) {
                        $changeParts[] = 'без ' . $dish->name;
                        continue;
                    }

                    // Зміни інгредієнтів
                    $ingParts = [];
                    $this->collectIngredientNoteParts($dish, $order, $dishId, $ingParts);
                    foreach ($ingParts as $p) {
                        $changeParts[] = $p;
                    }
                }
            }

            // Виробничий коментар
            $comment = trim($order->client?->production_comment ?? '');

            $hasChanges = !empty($changeParts) || $comment !== '';

            $rows[] = [
                'client_id'  => $order->client?->id,
                'calories'   => (int) $order->calories,
                'is_evening' => $isEvening,
                'changes'    => implode('; ', array_unique($changeParts)),
                'comment'    => $comment,
                'has_changes'=> $hasChanges,
            ];
        }

        // Сортуємо за калоражем
        usort($rows, fn($a, $b) => $a['calories'] <=> $b['calories']);

        // Зведена статистика по калоражу
        $calorieLevels = collect($rows)->pluck('calories')->unique()->sort()->values()->toArray();

        $stats = [];
        foreach ($calorieLevels as $cal) {
            $group         = collect($rows)->where('calories', $cal);
            $evening       = $group->where('is_evening', true);
            $morning       = $group->where('is_evening', false);
            $stats[$cal]   = [
                'total'           => $group->count(),
                'total_ind'       => $group->where('has_changes', true)->count(),
                'evening'         => $evening->count(),
                'evening_ind'     => $evening->where('has_changes', true)->count(),
                'morning'         => $morning->count(),
                'morning_ind'     => $morning->where('has_changes', true)->count(),
            ];
        }

        $totalAll     = count($rows);
        $totalInd     = collect($rows)->where('has_changes', true)->count();
        $totalEvening = collect($rows)->where('is_evening', true)->count();
        $totalEveningInd = collect($rows)->where('is_evening', true)->where('has_changes', true)->count();
        $totalMorning = collect($rows)->where('is_evening', false)->count();
        $totalMorningInd = collect($rows)->where('is_evening', false)->where('has_changes', true)->count();

        $eveningRows = collect($rows)->where('is_evening', true)->where('has_changes', true)->values();
        $morningRows = collect($rows)->where('is_evening', false)->where('has_changes', true)->values();

        return view('print.assembly-sheet', [
            'date'            => Carbon::parse($targetDate)->format('Y-m-d'),
            'stats'           => $stats,
            'calorieLevels'   => $calorieLevels,
            'totalAll'        => $totalAll,
            'totalInd'        => $totalInd,
            'totalEvening'    => $totalEvening,
            'totalEveningInd' => $totalEveningInd,
            'totalMorning'    => $totalMorning,
            'totalMorningInd' => $totalMorningInd,
            'eveningRows'     => $eveningRows,
            'morningRows'     => $morningRows,
        ]);
    }

    public function kitchenMenu(Request $request)
    {
        $date = $request->input('date', now()->format('Y-m-d'));

        // Якщо ?plan_id=X — беремо саме той план, інакше дефолтний
        $plan = null;
        if ($pid = $request->integer('plan_id')) {
            $plan = MenuPlan::find($pid);
        }

        [$menu, $globalDay] = $this->getMenuForTargetDate($date, $plan);

        if (!$menu) {
            $planLabel = $plan?->name ? " ({$plan->name})" : '';
            return "Меню на цю дату не знайдено (День циклу №{$globalDay}{$planLabel}).";
        }

        $dishes = $menu->menuItems
            ->sortBy(fn($item) => $item->mealType?->sort_order ?? 99)
            ->filter(fn($item) => $item->dish)
            ->groupBy(fn($item) => $item->mealType?->name ?? 'Інше');

        return view('print.kitchen-menu', [
            'dishes'    => $dishes,
            'date'      => Carbon::parse($date)->format('d.m.Y'),
            'rawDate'   => $date,
            'globalDay' => $globalDay,
        ]);
    }

    /**
     * Денне меню + день циклу для конкретного плану.
     * Якщо план не передано — повертає для дефолтного плану (бек-сумісність).
     *
     * @return array{0: ?DailyMenu, 1: int}
     */
    private function getMenuForTargetDate(string $targetDate, ?MenuPlan $plan = null): array
    {
        $plan = $plan ?? MenuPlan::default();
        if (!$plan) return [null, 0];

        $globalDay = $plan->globalDayFor($targetDate);

        $menu = DailyMenu::where('menu_plan_id', $plan->id)
            ->where('day_number', $globalDay)
            ->with([
                'menuItems.dish.dishIngredients.ingredient.allergens',
                'menuItems.dish.dishIngredients.childDish.dishIngredients.ingredient.allergens',
                'menuItems.mealType',
            ])
            ->first();

        return [$menu, $globalDay];
    }

    /**
     * Меню для конкретного замовлення на дату — використовує план замовлення.
     *
     * @return array{0: ?DailyMenu, 1: int, 2: ?MenuPlan}
     */
    private function getMenuForOrder(Order $order, string $targetDate): array
    {
        $plan = $order->effectiveMenuPlan();
        if (!$plan) return [null, 0, null];

        [$menu, $globalDay] = $this->getMenuForTargetDate($targetDate, $plan);
        return [$menu, $globalDay, $plan];
    }

    /**
     * Меню всіх потрібних планів на дату (для aggregator-сторінок).
     * Повертає `[plan_id => [menu, globalDay, plan]]`.
     */
    private function getMenusForOrders($orders, string $targetDate): array
    {
        $byPlan = [];
        foreach ($orders as $order) {
            $plan = $order->effectiveMenuPlan();
            if (!$plan) continue;
            if (isset($byPlan[$plan->id])) continue;

            [$menu, $globalDay] = $this->getMenuForTargetDate($targetDate, $plan);
            $byPlan[$plan->id] = ['menu' => $menu, 'global_day' => $globalDay, 'plan' => $plan];
        }
        return $byPlan;
    }

    private function findIngredientChanges($dishOrChildDish, $order, $rootDishId)
    {
        $changes = [];

        if (!$dishOrChildDish || !$dishOrChildDish->dishIngredients) {
            return $changes;
        }

        foreach ($dishOrChildDish->dishIngredients as $di) {
            if ($di->ingredient) {
                if ($order->effectiveExcludedIngredients()->contains('id', $di->ingredient->id)) {
                    $ingRep = $order->replacements
                        ->where('dish_id', $rootDishId)
                        ->where('original_product_id', $di->ingredient->id)
                        ->first();

                    if ($ingRep && $ingRep->force_approved) {
                        // одобрено примусово — не показуємо як виключення
                    } elseif ($ingRep && $ingRep->replacementProduct) {
                        $changes[] = $di->ingredient->name . " → " . $ingRep->replacementProduct->name;
                    } else {
                        $changes[] = "БЕЗ: " . $di->ingredient->name;
                    }
                }
            }

            if ($di->childDish) {
                $subChanges = $this->findIngredientChanges($di->childDish, $order, $rootDishId);
                $changes = array_merge($changes, $subChanges);
            }
        }

        return $changes;
    }

    private function collectIngredientNoteParts($dish, $order, $rootDishId, array &$parts): void
    {
        if (!$dish || !$dish->dishIngredients) return;

        foreach ($dish->dishIngredients as $di) {
            if ($di->ingredient_id && $order->effectiveExcludedIngredients()->contains('id', $di->ingredient_id)) {
                $rep = $order->replacements
                    ->where('dish_id', $rootDishId)
                    ->where('original_product_id', $di->ingredient_id)
                    ->first();
                if ($rep && $rep->force_approved) {
                    // Одобрено — не показуємо
                } elseif ($rep && $rep->replacementProduct) {
                    $parts[] = ($di->ingredient->name ?? '?') . " → " . $rep->replacementProduct->name;
                } else {
                    $parts[] = "Без: " . ($di->ingredient->name ?? '?');
                }
            }
            if ($di->child_dish_id && $di->childDish) {
                $this->collectIngredientNoteParts($di->childDish, $order, $rootDishId, $parts);
            }
        }
    }

    private function getConflictingIngredients($dish, $exclusions, $prefix = ''): array
    {
        $found = [];

        if (!$dish || !$dish->dishIngredients) return [];

        foreach ($dish->dishIngredients as $di) {
            if ($di->ingredient_id && $exclusions->contains('id', $di->ingredient_id)) {
                $found[] = $di->ingredient->name . ($prefix ? " (у {$prefix})" : "");
            }
            if ($di->child_dish_id && $di->childDish) {
                $found = array_merge($found, $this->getConflictingIngredients($di->childDish, $exclusions, $di->childDish->name));
            }
        }

        return $found;
    }
    
    private function calculateIngredientsStructureByScales($dish, array $scales): array
    {
        if (empty($scales)) return [];
        $totalScale = array_sum($scales);
        return $this->getHierarchicalIngredients($dish, $totalScale, 1.0, null, false, null);
    }

    private function calculateStructureTotals(array $components): array
    {
        $netto = 0.0; $brutto = 0.0;
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
        if ($dishReplacement && $dishReplacement->replacementDish) {
            $replacementDishName = $dishReplacement->replacementDish->name;
            $components = $this->getHierarchicalIngredients($dishReplacement->replacementDish, $scale, 1.0, $dishReplacement->replacementDish->id, true, $order);
        } else {
            $components = $this->getHierarchicalIngredients($dish, $scale, 1.0, $dish->id, true, $order);
        }

        $totals = $this->calculateStructureTotals($components);
        
        $finalComment = trim($order->client->production_comment ?? '');

        return [
            'client_name' => $order->client->name,
            'order_id' => $order->id,
            'comment' => $finalComment,
            'dish_excluded' => $dishExclusion,
            'dish_replacement' => $replacementDishName,
            'components' => $components,
            'total_netto' => $totals['netto'],
            'total_brutto' => $totals['brutto'],
        ];
    }

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
            $isProduct = in_array($type, ['product', 'продукт'], true);
            $isPf = in_array($type, ['pf', 'напівфабрикат', 'п/ф', 'н/ф'], true);

            if ($checkConflicts && $specificOrder && $isProduct && $di->ingredient) {
                $ingId = (int)$di->ingredient->id;
                if ($specificOrder->effectiveExcludedIngredients()->contains('id', $ingId)) {
                    $rep = $specificOrder->replacements->where('dish_id', $rootDishId)->where('original_product_id', $ingId)->first();
                    if ($rep && $rep->force_approved) {
                        $conflictData = null; // примусово одобрено — без конфлікту
                    } else {
                        $replacementInfo = null;
                        if ($rep && $rep->replacementProduct) {
                            $newYield = (float)($rep->replacementProduct->yield_percent ?: 100);
                            if ($newYield <= 0) $newYield = 100;
                            $replacementInfo = [
                                'name' => $rep->replacementProduct->name,
                                'netto' => round($nettoTotalRaw, 1),
                                'brutto' => round(($nettoTotalRaw * 100) / $newYield, 1),
                            ];
                        }
                        $conflictData = [
                            'is_resolved' => (bool)$replacementInfo,
                            'replacement' => $replacementInfo,
                            'allergen'    => $di->ingredient->allergens->pluck('name')->join(', ') ?: null,
                        ];
                    }
                }
            }

            if ($isProduct && $di->ingredient) {
                $yield = (float)($di->ingredient->yield_percent ?: 100);
                if ($yield <= 0) $yield = 100;
                $components[] = [
                    'type' => 'product',
                    'name' => $di->ingredient->name,
                    'weight_netto' => round($nettoTotalRaw, 1),
                    'weight_brutto' => round(($nettoTotalRaw * 100) / $yield, 1),
                    'conflict' => $conflictData,
                ];
                continue;
            }

            if ($isPf && $di->childDish) {
                $pfTotals = $di->childDish->calculated_totals;
                $pfOutput = (float)($pfTotals['output_weight'] ?? 0);
                if ($pfOutput <= 0) continue;

                $pfRatio = ((float)($di->net_weight_g ?? 0)) / $pfOutput;
                $subIngredients = $this->getHierarchicalIngredients($di->childDish, $scale, ($pfRatio * $subRatio), $rootDishId, $checkConflicts, $specificOrder);
                
                $sumNetto = 0.0; $sumBrutto = 0.0;
                foreach ($subIngredients as $s) {
                    $sumNetto += (float)($s['weight_netto'] ?? ($s['weight_output'] ?? 0));
                    $sumBrutto += (float)($s['weight_brutto'] ?? ($s['weight_brutto_sum'] ?? 0));
                }

                $components[] = [
                    'type' => 'pf',
                    'name' => $di->childDish->name,
                    'recipe' => $di->childDish->description,
                    'weight_output' => round($nettoTotalRaw, 1),
                    'weight_netto_sum' => round($sumNetto, 1),
                    'weight_brutto_sum' => round($sumBrutto, 1),
                    'weight_netto' => round($sumNetto, 1),
                    'weight_brutto' => round($sumBrutto, 1),
                    'sub_ingredients' => $subIngredients
                ];
            }
        }
        return $components;
    }

    public function cycleMenu(Request $request)
    {
        // Дозволяємо ?plan_id=X, інакше — дефолтний план
        $planId = $request->integer('plan_id') ?: optional(MenuPlan::default())->id;

        $menus = DailyMenu::with([
            'menuItems.mealType',
            'menuItems.dish.dishIngredients.ingredient',
            'menuItems.dish.dishIngredients.childDish.dishIngredients.ingredient',
        ])
            ->when($planId, fn ($q) => $q->where('menu_plan_id', $planId))
            ->orderBy('day_number')
            ->get();

        return view('print.cycle-menu', compact('menus'));
    }

    public function dailyMenuTechCards(Request $request, int $dailyMenuId)
    {
        $dailyMenu = DailyMenu::with([
            'menuItems.mealType',
            'menuItems.dish.dishIngredients.ingredient.allergens',
            'menuItems.dish.dishIngredients.childDish.dishIngredients.ingredient.allergens',
            'menuItems.dish.dishIngredients.childDish.dishIngredients.childDish.dishIngredients.ingredient',
        ])->findOrFail($dailyMenuId);

        $dishes = $dailyMenu->menuItems
            ->sortBy(fn ($item) => $item->mealType?->sort_order ?? 99)
            ->map(function ($item) {
                return [
                    'meal_type' => $item->mealType?->name ?? '—',
                    'dish'      => $item->dish,
                    'ingredients' => $item->dish
                        ? $this->flattenDishIngredients($item->dish, 1.0, 0)
                        : [],
                ];
            })
            ->filter(fn ($d) => $d['dish'] !== null)
            ->values();

        return view('print.daily-menu-tech-cards', compact('dailyMenu', 'dishes'));
    }

    public function dishTechCard(Request $request, int $dishId)
    {
        $dish = Dish::with([
            'dishIngredients.ingredient.allergens',
            'dishIngredients.childDish.dishIngredients.ingredient.allergens',
            'dishIngredients.childDish.dishIngredients.childDish.dishIngredients.ingredient',
        ])->findOrFail($dishId);

        $ingredients = $this->flattenDishIngredients($dish, 1.0, 0);

        return view('print.dish-tech-card', compact('dish', 'ingredients'));
    }

    private function flattenDishIngredients(Dish $dish, float $ratio, int $level): array
    {
        $rows = [];

        foreach ($dish->dishIngredients as $item) {
            $type      = mb_strtolower(trim((string)($item->type ?? '')));
            $netWeight = (float)($item->net_weight_g ?? 0) * $ratio;
            if ($netWeight <= 0) continue;

            $isProduct = in_array($type, ['product', 'продукт'], true);
            $isPf      = in_array($type, ['pf', 'напівфабрикат', 'п/ф', 'н/ф'], true);

            if ($isProduct && $item->ingredient) {
                $ing   = $item->ingredient;
                $yield = (float)($ing->yield_percent ?: 100);
                if ($yield <= 0) $yield = 100;
                $grossWeight = $netWeight * 100.0 / $yield;

                $avgPrice  = (float)($ing->average_price ?? 0);
                $basePrice = (float)($ing->price_per_kg ?? 0);
                $unitPrice = $avgPrice > 0 ? $avgPrice : $basePrice;
                $unit      = mb_strtolower(trim((string)($ing->unit ?? 'кг')));
                $pricePerGram = match(true) {
                    in_array($unit, ['кг', 'kg', 'л', 'l'])   => $unitPrice / 1000.0,
                    in_array($unit, ['г',  'g',  'мл', 'ml']) => $unitPrice,
                    default                                    => $unitPrice / 1000.0,
                };
                $cost = $pricePerGram * $grossWeight;

                $prot = (float)($ing->proteins_100g ?? 0) * $netWeight / 100.0;
                $fat  = (float)($ing->fats_100g     ?? 0) * $netWeight / 100.0;
                $carb = (float)($ing->carbs_100g    ?? 0) * $netWeight / 100.0;
                $kcal = $prot * 4.0 + $fat * 9.0 + $carb * 4.0;

                $rows[] = [
                    'level'     => $level,
                    'type'      => 'product',
                    'name'      => $ing->name,
                    'net'       => round($netWeight, 1),
                    'gross'     => round($grossWeight, 1),
                    'yield'     => (int)$yield,
                    'prot'      => round($prot, 1),
                    'fat'       => round($fat, 1),
                    'carb'      => round($carb, 1),
                    'kcal'      => round($kcal, 1),
                    'cost'      => round($cost, 2),
                    'allergens' => $ing->allergens->pluck('name')->join(', '),
                ];

            } elseif ($isPf && $item->childDish) {
                $childDish = $item->childDish;
                $pfTotals  = $childDish->calculated_totals;
                $pfOutput  = (float)($pfTotals['output_weight'] ?? 0);
                $pfInput   = (float)($pfTotals['input_weight']  ?? 0);
                $pfRatio   = $pfOutput > 0 ? $netWeight / $pfOutput : 0;

                $prot = (float)($pfTotals['prot'] ?? 0) * $pfRatio;
                $fat  = (float)($pfTotals['fat']  ?? 0) * $pfRatio;
                $carb = (float)($pfTotals['carb'] ?? 0) * $pfRatio;
                $kcal = $prot * 4.0 + $fat * 9.0 + $carb * 4.0;
                $cost = (float)($pfTotals['cost'] ?? 0) * $pfRatio;

                $rows[] = [
                    'level'     => $level,
                    'type'      => 'pf',
                    'name'      => $childDish->name,
                    'net'       => round($netWeight, 1),
                    'gross'     => round($netWeight, 1),
                    'yield'     => $pfInput > 0 ? (int)round($pfOutput / $pfInput * 100) : 100,
                    'prot'      => round($prot, 1),
                    'fat'       => round($fat, 1),
                    'carb'      => round($carb, 1),
                    'kcal'      => round($kcal, 1),
                    'cost'      => round($cost, 2),
                    'allergens' => '',
                    'pf_output' => round($pfOutput, 1),
                    'pf_input'  => round($pfInput, 1),
                    'pf_kcal'   => round((float)($pfTotals['kcal'] ?? 0), 1),
                    'pf_prot'   => round((float)($pfTotals['prot'] ?? 0), 1),
                    'pf_fat'    => round((float)($pfTotals['fat']  ?? 0), 1),
                    'pf_carb'   => round((float)($pfTotals['carb'] ?? 0), 1),
                    'pf_cost'   => round((float)($pfTotals['cost'] ?? 0), 2),
                ];

                $subRows = $this->flattenDishIngredients($childDish, $pfRatio, $level + 1);
                $rows    = array_merge($rows, $subRows);
            }
        }

        return $rows;
    }
}