<?php

namespace App\Http\Controllers;

use App\Models\DailyMenu;
use App\Models\Order;
use App\Models\Setting;
use Illuminate\Http\Request;
use Carbon\Carbon;

class PrintController extends Controller
{
    public function manifest(Request $request)
    {
        $inputDate  = $request->input('date', now()->format('Y-m-d'));
        $layout     = $request->input('layout', 'default');
        $targetDate = Carbon::parse($inputDate)->addDay()->format('Y-m-d');

        [$menu, $globalDay] = $this->getMenuForTargetDate($targetDate);

        if (!$menu) {
            return "На День циклу №{$globalDay} меню ще не створено.";
        }

        $orders = Order::whereHas('orderDays', function ($query) use ($targetDate) {
                $query->where('date', $targetDate);
            })
            ->with(['client.mealTypes', 'projectData', 'orderDays' => fn($q) => $q->where('date', $targetDate)])
            ->get();

        $manifests = [];

        foreach ($orders as $order) {
            $calc = $this->calculateOrderPlan($order, $menu);

            if (empty($calc['items'])) {
                continue;
            }

            $orderDay = $order->orderDays->first();
            $address = $orderDay?->address
                ?? $order->client?->addresses()->where('is_default', true)->first()?->address
                ?? $order->client?->address
                ?? 'Самовивіз';

            $manifests[] = [
                'client_id'   => $order->client?->id ?? '---',
                'has_cutlery' => (bool) ($order->client?->has_cutlery ?? true),
                'project'     => $order->project,
                'client'      => $order->client?->name ?? 'Без імені',
                'address'     => $address,
                'calories'    => (int) $order->calories,
                'comment'     => $order->client?->production_comment,
                'items'       => $calc['items'],
                'date'        => $targetDate,
                'menu_token'  => $order->menu_token,
                'nutrition'   => [
                    'b' => round($calc['totals']['prot']),
                    'j' => round($calc['totals']['fat']),
                    'u' => round($calc['totals']['carb']),
                ],
            ];
        }

        usort($manifests, function ($a, $b) {
            if ($a['calories'] === $b['calories']) {
                return strcmp($a['client'], $b['client']);
            }
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

        $orders = Order::whereHas('orderDays', function ($query) use ($targetDate) {
                $query->where('date', $targetDate);
            })
            ->with(['client', 'projectData', 'orderDays' => fn($q) => $q->where('date', $targetDate)])
            ->get();

        $manifests = [];

        foreach ($orders as $order) {
            $orderDay = $order->orderDays->first();
            $address = $orderDay?->address
                ?? $order->client?->addresses()->where('is_default', true)->first()?->address
                ?? $order->client?->address
                ?? 'Самовивіз';

            $isEvening = str_contains((string) $order->delivery_time, 'evening')
                      || str_contains((string) $order->schedule_type, 'evening');

            $manifests[] = [
                'client_id'     => $order->client?->id ?? '---',
                'project'       => $order->project,
                'client'        => $order->client?->name ?? 'Без імені',
                'address'       => $address,
                'calories'      => (int) $order->calories,
                'is_evening'    => $isEvening,
                'delivery_slot' => $isEvening ? 'Вечір' : 'Ранок',
                'menu_token'    => $order->menu_token,
            ];
        }

        usort($manifests, function ($a, $b) {
            // 1. Спочатку ранок, потім вечір
            if ($a['is_evening'] !== $b['is_evening']) {
                return $a['is_evening'] ? 1 : -1;
            }
            // 2. Всередині — за проєктом
            $projectCmp = strcmp($a['project'] ?? '', $b['project'] ?? '');
            if ($projectCmp !== 0) {
                return $projectCmp;
            }
            // 3. Всередині проєкту — за іменем
            return strcmp($a['client'], $b['client']);
        });

        // 🔥 ВИПРАВЛЕННЯ: Передаємо базову дату
        $date = $inputDate; 
        return view('print.mini-manifest', compact('manifests', 'date'));
    }

    public function stickers(Request $request)
    {
        $inputDate  = $request->input('date', now()->format('Y-m-d'));
        $targetDate = Carbon::parse($inputDate)->addDay()->format('Y-m-d');

        [$menu, $globalDay] = $this->getMenuForTargetDate($targetDate);

        if (!$menu) {
            return "Меню не створено на завтра ({$targetDate}).";
        }

        $orders = Order::whereHas('orderDays', function ($query) use ($targetDate) {
                $query->where('date', $targetDate);
            })
            ->with([
                'client.mealTypes',
                'client.ingredientExclusions',
                'client.dishExclusions',
                'projectData', 
                'replacements.replacementProduct',
                'replacements.replacementDish',
            ])
            ->get();

        $stickers = [];

        foreach ($orders as $order) {
            $calc = $this->calculateOrderPlan($order, $menu);

            if (empty($calc['items'])) {
                continue;
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
            // 3. За іменем клієнта
            return strcmp($a['client'], $b['client']);
        });

        // 🔥 ВИПРАВЛЕННЯ: Передаємо базову дату
        $date = $inputDate; 
        return view('print.stickers', compact('stickers', 'date'));
    }

    public function packagingList(Request $request)
    {
        $inputDate  = $request->input('date', now()->format('Y-m-d'));
        $targetDate = Carbon::parse($inputDate)->addDay()->format('Y-m-d');

        [$menu, $globalDay] = $this->getMenuForTargetDate($targetDate);

        if (!$menu) {
            return "Меню не знайдено на завтра ({$targetDate})";
        }

        $orders = Order::whereHas('orderDays', function ($query) use ($targetDate) {
                $query->where('date', $targetDate);
            })
            ->with([
                'client.mealTypes',
                'client.ingredientExclusions',
                'client.dishExclusions',
                'replacements.replacementProduct',
                'replacements.replacementDish',
                'replacements.originalProduct',
                'projectData',
            ])
            ->get();

        $report = [];

        $sortedMenuItems = $menu->menuItems->sortBy(fn($item) => $item->mealType?->sort_order ?? 99);

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
                $calc = $this->calculateOrderPlan($order, $menu);

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

                // 1. Коментар клієнта
                $comment = trim($order->client->production_comment ?? '');
                if (!empty($comment)) {
                    $tableData['individual_notes'][] = array_merge($clientMeta, ['text' => $comment]);
                }

                // 2. Виключення/заміна цілої страви
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

        // 🔥 ВИПРАВЛЕННЯ: Передаємо базову дату
        $date = $inputDate; 
        return view('print.packaging-list', compact('report', 'date'));
    }

    public function productionReport(Request $request)
    {
        $inputDate = $request->input('date', now()->format('Y-m-d'));
        $targetDate = Carbon::parse($inputDate)->addDay()->format('Y-m-d');

        [$menu, $globalDay] = $this->getMenuForTargetDate($targetDate);

        if (!$menu) {
            return "Меню не знайдено на завтра ({$targetDate})";
        }

        $orders = Order::whereHas('orderDays', function ($query) use ($targetDate) {
                $query->where('date', $targetDate);
            })
            ->with([
                'client.mealTypes',
                'client.ingredientExclusions',
                'client.dishExclusions',
                'replacements.replacementProduct',
                'replacements.replacementDish.dishIngredients.ingredient',
            ])
            ->get();

        if ($orders->isEmpty()) {
            return "Немає активних замовлень на {$targetDate}.";
        }

        $orderPlans = [];
        foreach ($orders as $order) {
            $orderPlans[$order->id] = $this->calculateOrderPlan($order, $menu);
        }

        $report = [];
        $sortedMenuItems = $menu->menuItems->sortBy(fn($item) => $item->mealType?->sort_order ?? 99);

        foreach ($sortedMenuItems as $item) {
            if (!$item->dish) continue;

            $mealName = $item->mealType->name ?? 'Інше';
            $dish = $item->dish;

            $standard = [];
            $custom = [];
            $commentClients = [];

            foreach ($orders as $order) {
                $plan = $orderPlans[$order->id] ?? null;
                if (!$plan) continue;

                $plannedWeight = collect($plan['items'])->first(function ($it) use ($dish, $item) {
                    return (int)$it['dish_id'] === (int)$dish->id && (int)$it['meal_type_id'] === (int)$item->meal_type_id;
                })['weight'] ?? null;

                if ($plannedWeight === null) continue;

                $baseW = (float)($dish->base_weight_g ?? 0);
                $dishScale = ($baseW > 0) ? ((float)$plannedWeight / $baseW) : 0.0;

                // Коментар — це нотатка, не причина для окремої картки
                if (!empty(trim($order->client->production_comment ?? ''))) {
                    $commentClients[] = [
                        'client_name' => $order->client->name,
                        'order_id'    => $order->id,
                        'comment'     => trim($order->client->production_comment),
                    ];
                }

                $isCustom =
                    $order->replacements->where('dish_id', $dish->id)->isNotEmpty()
                    || $order->client->dishExclusions->contains('id', $dish->id)
                    || !empty($this->getConflictingIngredients($dish, $order->client->ingredientExclusions));

                if ($isCustom) {
                    $custom[] = ['order' => $order, 'scale' => $dishScale];
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

            $report[$mealName][] = [
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

        return view('print.production-report', [
            'report' => $report,
            'date' => Carbon::parse($inputDate)->format('d.m.Y'),       
            'targetDateFormatted' => Carbon::parse($targetDate)->format('d.m.Y'),
            'targetDate' => $targetDate,
            'dayNumber' => $globalDay
        ]);
    }

    public function shoppingList(Request $request)
    {
        $date = $request->input('date', now()->addDay()->format('Y-m-d'));

        $cycleDays  = (int) Setting::where('key', 'menu_cycle_days')->value('value') ?: 24;
        $startDate  = Setting::where('key', 'menu_cycle_start_date')->value('value') ?: '2025-01-01';
        $anchorDate = Carbon::parse($startDate);
        $carbonDate = Carbon::parse($date);
        $diff       = abs($carbonDate->diffInDays($anchorDate));
        $globalDay  = ($diff % $cycleDays) + 1;

        $menu = DailyMenu::where('day_number', $globalDay)
            ->with([
                'menuItems.dish.dishIngredients.ingredient',
                'menuItems.dish.dishIngredients.childDish.dishIngredients.ingredient',
                'menuItems.mealType',
            ])->first();

        $shoppingList = [];

        if ($menu) {
            $orders = Order::whereHas('orderDays', fn ($q) => $q->where('date', $date))
                ->whereIn('status', ['new', 'active'])
                ->with([
                    'client.mealTypes', 'client.ingredientExclusions', 'client.dishExclusions',
                    'replacements.replacementProduct',
                    'replacements.replacementDish.dishIngredients.ingredient',
                ])->get();

            $orderPlans = [];
            foreach ($orders as $order) {
                $orderPlans[$order->id] = $this->calculateOrderPlan($order, $menu);
            }

            $bruttoByIng = [];

            foreach ($menu->menuItems->sortBy(fn ($i) => $i->mealType?->sort_order ?? 99) as $item) {
                if (!$item->dish) continue;
                $dish = $item->dish;

                foreach ($orders as $order) {
                    $plan = $orderPlans[$order->id] ?? null;
                    if (!$plan) continue;

                    $plannedWeight = collect($plan['items'])->first(
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
            'shoppingList'        => $shoppingList,
            'date'                => Carbon::parse($date)->format('d.m.Y'),
            'dayNumber'           => $globalDay,
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

                $isExcluded = $order?->client->ingredientExclusions->contains('id', $di->ingredient->id);
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

        [$menu, $globalDay] = $this->getMenuForTargetDate($targetDate);

        if (!$menu) {
            return "Меню не знайдено на завтра ({$targetDate})";
        }

        $orders = Order::whereHas('orderDays', function ($query) use ($targetDate) {
                $query->where('date', $targetDate);
            })
            ->with([
                'client.mealTypes',
                'client.ingredientExclusions',
                'client.dishExclusions',
                'replacements.replacementProduct',
                'replacements.replacementDish.dishIngredients.ingredient',
            ])
            ->get();

        if ($orders->isEmpty()) {
            return "Немає активних замовлень на {$targetDate}.";
        }

        $orderPlans = [];
        foreach ($orders as $order) {
            $orderPlans[$order->id] = $this->calculateOrderPlan($order, $menu);
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

        $sortedMenuItems = $menu->menuItems->sortBy(fn($item) => $item->mealType?->sort_order ?? 99);

        foreach ($sortedMenuItems as $item) {
            if (!$item->dish) continue;

            $dish = $item->dish;
            $standard = [];
            $custom = [];

            foreach ($orders as $order) {
                $plan = $orderPlans[$order->id] ?? null;
                if (!$plan) continue;

                $plannedWeight = collect($plan['items'])->first(function ($it) use ($dish, $item) {
                    return (int)$it['dish_id'] === (int)$dish->id && (int)$it['meal_type_id'] === (int)$item->meal_type_id;
                })['weight'] ?? null;

                if ($plannedWeight === null) continue;

                $baseW = (float)($dish->base_weight_g ?? 0);
                $dishScale = ($baseW > 0) ? ((float)$plannedWeight / $baseW) : 0.0;

                $isCustom =
                    $order->replacements->where('dish_id', $dish->id)->isNotEmpty()
                    || $order->client->dishExclusions->contains('id', $dish->id)
                    || !empty($this->getConflictingIngredients($dish, $order->client->ingredientExclusions));

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

        ksort($summaryIngredients);

        return view('print.stock-list', [
            'ingredients'        => $summaryIngredients,
            'date'               => Carbon::parse($inputDate)->format('d.m.Y'),
            'targetDateFormatted'=> Carbon::parse($targetDate)->format('d.m.Y'),
            'dayNumber'          => $globalDay,
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

    private function calculateOrderPlan(Order $order, DailyMenu $menu): array
    {
        $targetKcal = (float) ($order->calories ?? 0);
        if ($targetKcal <= 0) {
            return ['items' => [], 'totals' => ['kcal' => 0, 'prot' => 0, 'fat' => 0, 'carb' => 0]];
        }

        $clientMealTypeIds = $order->client?->mealTypes?->pluck('id')->toArray() ?? [];

        $availableItems = $menu->menuItems
            ->filter(fn($item) => $item->dish && in_array($item->meal_type_id, $clientMealTypeIds, true))
            ->sortBy(fn($item) => $item->mealType?->sort_order ?? 99)
            ->values();

        if ($availableItems->isEmpty()) {
            return ['items' => [], 'totals' => ['kcal' => 0, 'prot' => 0, 'fat' => 0, 'carb' => 0]];
        }

        $allowedSortOrders = \App\Models\MealPlan::getAllowedSortOrders((int)$targetKcal);
        $selectedItems = $availableItems->filter(
            fn ($item) => in_array($item->mealType?->sort_order, $allowedSortOrders)
        )->values();

        if ($selectedItems->isEmpty()) {
            return ['items' => [], 'totals' => ['kcal' => 0, 'prot' => 0, 'fat' => 0, 'carb' => 0]];
        }

        $byMeal = $selectedItems->groupBy('meal_type_id');

        // Нормалізація відсотків до 100% для вибраних страв
        $rawPct = [];
        foreach ($byMeal as $mealTypeId => $items) {
            $fi = $items->first();
            $rawPct[$mealTypeId] = $fi->custom_energy_percent !== null
                ? (float) $fi->custom_energy_percent
                : (float) ($fi->mealType?->energy_percent ?? 0);
        }
        $totalPct = array_sum($rawPct);
        $normFactor = ($totalPct > 0.5 && abs($totalPct - 100) > 0.5) ? (100.0 / $totalPct) : 1.0;

        $totals = ['kcal' => 0.0, 'prot' => 0.0, 'fat' => 0.0, 'carb' => 0.0];
        $resultItems = [];

        foreach ($byMeal as $mealTypeId => $items) {
            $firstItem = $items->first();
            $mealType = $firstItem->mealType;

            $p = ($rawPct[$mealTypeId] ?? 0) * $normFactor;

            $mealKcal = ($p > 0)
                ? $targetKcal * ($p / 100.0)
                : $targetKcal * (1.0 / max(1, $byMeal->count()));

            $countInMeal = max(1, $items->count());
            $kcalPerDish = $mealKcal / $countInMeal;

            foreach ($items as $item) {
                $dish = $item->dish;
                if (!$dish) continue;

                $kcalPer100 = $this->dishKcalPer100g($dish);
                $weight = ($kcalPer100 > 0)
                    ? (int) round(($kcalPerDish / $kcalPer100) * 100.0)
                    : 0;

                $baseW = (float) ($dish->base_weight_g ?? 0);
                $protPerG = ($baseW > 0) ? ((float)($dish->total_prot ?? 0) / $baseW) : 0.0;
                $fatPerG  = ($baseW > 0) ? ((float)($dish->total_fat  ?? 0) / $baseW) : 0.0;
                $carbPerG = ($baseW > 0) ? ((float)($dish->total_carb ?? 0) / $baseW) : 0.0;

                $totals['kcal'] += ($weight * $kcalPer100 / 100.0);
                $totals['prot'] += ($weight * $protPerG);
                $totals['fat']  += ($weight * $fatPerG);
                $totals['carb'] += ($weight * $carbPerG);

                $resultItems[] = [
                    'meal' => $mealType?->name ?? '-',
                    'dish' => $dish->name,
                    'weight' => $weight,
                    'dish_id' => (int) $dish->id,
                    'meal_type_id' => (int) $mealTypeId,
                ];
            }
        }

        usort($resultItems, function ($a, $b) use ($menu) {
            $aSort = $menu->menuItems->firstWhere('meal_type_id', $a['meal_type_id'])?->mealType?->sort_order ?? 99;
            $bSort = $menu->menuItems->firstWhere('meal_type_id', $b['meal_type_id'])?->mealType?->sort_order ?? 99;
            return $aSort <=> $bSort;
        });

        return [
            'items' => $resultItems,
            'totals' => $totals,
        ];
    }


    private function dishKcalPer100g($dish): float
    {
        $baseW = (float) ($dish->base_weight_g ?? 0);
        $totalKcal = (float) ($dish->total_kcal ?? 0);

        if ($baseW <= 0 || $totalKcal <= 0) {
            return 0.0;
        }

        return ($totalKcal / $baseW) * 100.0;
    }

    private function getMenuForTargetDate(string $targetDate): array
    {
        $cycleDays    = (int) Setting::where('key', 'menu_cycle_days')->value('value') ?: 24;
        $startDateStr = Setting::where('key', 'menu_cycle_start_date')->value('value') ?: '2025-01-01';
        $anchorDate   = Carbon::parse($startDateStr);

        $globalDay = (abs(Carbon::parse($targetDate)->diffInDays($anchorDate)) % $cycleDays) + 1;

        $menu = DailyMenu::where('day_number', $globalDay)
            ->with([
                'menuItems.dish.dishIngredients.ingredient.allergens',
                'menuItems.dish.dishIngredients.childDish.dishIngredients.ingredient.allergens',
                'menuItems.mealType',
            ])
            ->first();

        return [$menu, $globalDay];
    }

    private function findIngredientChanges($dishOrChildDish, $order, $rootDishId)
    {
        $changes = [];

        if (!$dishOrChildDish || !$dishOrChildDish->dishIngredients) {
            return $changes;
        }

        foreach ($dishOrChildDish->dishIngredients as $di) {
            if ($di->ingredient) {
                if ($order->client->ingredientExclusions->contains('id', $di->ingredient->id)) {
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
            if ($di->ingredient_id && $order->client->ingredientExclusions->contains('id', $di->ingredient_id)) {
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
                if ($specificOrder->client->ingredientExclusions->contains('id', $ingId)) {
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

    public function cycleMenu()
    {
        $menus = DailyMenu::with([
            'menuItems.dish',
            'menuItems.mealType',
        ])->orderBy('day_number')->get();

        return view('print.cycle-menu', compact('menus'));
    }
}