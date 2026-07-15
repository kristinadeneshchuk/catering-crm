<?php

namespace App\Filament\Pages;

use Filament\Pages\Page;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use App\Models\DailyMenu;
use App\Models\Order;
use App\Models\Setting;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Form;
use Filament\Actions\Action;
use Carbon\Carbon;
use Illuminate\Support\HtmlString;
use App\Traits\CalculatesOrderPlan;

class PackagingList extends Page implements HasForms
{
    use InteractsWithForms, CalculatesOrderPlan;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-list';
    protected static ?string $navigationLabel = 'Фасувальний лист';
    protected static ?string $title = 'Фасувальний лист';
    protected static string $view = 'filament.pages.packaging-list';

    public ?array $data = [];
    public array $report = [];
    public array $clientComments = [];
    public array $missingPlans = []; // плани з замовленнями, у яких немає меню на цей день циклу
    public ?string $debugMessage = null;

    /** @var array<int, array{items: array, totals?: array}> */
    private array $orderPlans = [];

    public static function canAccess(): bool
    {
        return in_array(auth()->user()->role, ['admin', 'manager', 'cook'], true);
    }

    public function mount(): void
    {
        $this->form->fill(['date' => request()->query('date', now()->format('Y-m-d'))]);
        $this->calculate();
    }

    protected function getHeaderActions(): array
    {
        return [
            // 🔥 ПЕРШИЙ ВИПАДАЮЧИЙ СПИСОК: Друк
            \Filament\Actions\ActionGroup::make([
                Action::make('print_stickers')
                    ->label('1. Стікери')
                    ->icon('heroicon-o-tag')
                    ->color('gray')
                    ->url(fn () => route('print.stickers', ['date' => $this->data['date'] ?? now()->format('Y-m-d')]))
                    ->openUrlInNewTab(),

                Action::make('print_mini_manifest')
                    ->label('2. На пакет')
                    ->icon('heroicon-o-document-text')
                    ->color('info')
                    ->url(fn () => route('print.mini-manifest', ['date' => $this->data['date'] ?? now()->format('Y-m-d')]))
                    ->openUrlInNewTab(),

                Action::make('print_manifest')
                    ->label('3. В пакет (з меню)')
                    ->icon('heroicon-o-shopping-bag')
                    ->color('warning')
                    ->url(fn () => route('print.manifest', ['date' => $this->data['date'] ?? now()->format('Y-m-d')]))
                    ->openUrlInNewTab(),
            ])
            ->label('Друк маркування')
            ->icon('heroicon-o-printer')
            ->button()
            ->color('gray'),

            // 📦 Список упаковки по клієнтах
            Action::make('packaging_assembly')
                ->label('Список упаковки')
                ->icon('heroicon-o-archive-box')
                ->color('success')
                ->url(fn () => route('packaging.assembly', [
                    'date' => Carbon::parse($this->data['date'] ?? now()->format('Y-m-d'))->addDay()->format('Y-m-d')
                ]))
                ->openUrlInNewTab(),


            // 🔥 ДРУГИЙ ВИПАДАЮЧИЙ СПИСОК: Логістика
            \Filament\Actions\ActionGroup::make([
                Action::make('download_logistics_evening')
                    ->label('Вечір (Сьогодні)')
                    ->icon('heroicon-o-moon')
                    ->color('danger')
                    ->url(fn () => route('print.logistics', [
                        'date' => $this->data['date'] ?? now()->format('Y-m-d'),
                        'shift' => 'evening' 
                    ])),

                Action::make('download_logistics_morning')
                    ->label('Ранок (Завтра)')
                    ->icon('heroicon-o-sun')
                    ->color('success')
                    ->url(fn () => route('print.logistics', [
                        'date' => $this->data['date'] ?? now()->format('Y-m-d'),
                        'shift' => 'morning' 
                    ])),
            ])
            ->label('Логістика (Excel)')
            ->icon('heroicon-o-truck')
            ->button()
            ->color('primary'),
        ];
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            Grid::make(2)->schema([
                DatePicker::make('date')
                    ->label('Дата фасування (сьогодні)')
                    ->required()
                    ->live()
                    ->afterStateUpdated(fn () => $this->calculate()),

                \Filament\Forms\Components\Group::make()->schema([
                    Placeholder::make('info_text')
                        ->label('Цільова дата')
                        ->content(function () {
                            $selectedDate = $this->data['date'] ?? now()->format('Y-m-d');
                            $targetDateObj = Carbon::parse($selectedDate)->addDay();

                            $plan = \App\Models\MenuPlan::default();
                            $dayNum = $plan ? $plan->globalDayFor($targetDateObj) : 0;
                            $planName = $plan?->name ?? '—';

                            return new HtmlString(
                                "<div class='p-4 bg-gray-900 border border-gray-700 rounded-lg text-white'>
                                    Фасування на <strong class='text-primary-400'>завтра (" . $targetDateObj->format('d.m.Y') . ")</strong>.
                                    <br> План «{$planName}», <strong class='text-primary-400'>{$dayNum}-й день</strong> циклу.
                                </div>"
                            );
                        }),
                        
                    \Filament\Forms\Components\Actions::make([
                        \Filament\Forms\Components\Actions\Action::make('assembly_sheet')
                            ->label('Збірний лист')
                            ->icon('heroicon-o-clipboard-document-list')
                            ->color('gray')
                            ->url(fn () => route('print.assembly-sheet', ['date' => $this->data['date'] ?? now()->format('Y-m-d')]))
                            ->openUrlInNewTab(),
                        \Filament\Forms\Components\Actions\Action::make('print_view')
                            ->label('Відкрити версію для друку')
                            ->color('warning')
                            ->url(fn () => route('print.packaging-list', ['date' => $this->data['date'] ?? now()->format('Y-m-d')]))
                            ->openUrlInNewTab()
                    ])->alignRight(),
                ])
            ])
        ])->statePath('data');
    }

    public function calculate(): void
    {
        $selectedDate  = $this->data['date'] ?? now()->format('Y-m-d');
        $targetDateObj = Carbon::parse($selectedDate)->addDay();
        $targetDate    = $targetDateObj->format('Y-m-d');

        $this->report = []; // тепер: [$planId => ['plan'=>MenuPlan, 'day_number'=>int, 'tables'=>[...]]]
        $this->orderPlans = [];
        $this->missingPlans = [];
        $this->debugMessage = null;

        // Беремо активні та нові замовлення (призупинені — не фасуємо)
        $orders = Order::whereIn('status', ['new', 'active'])
            ->whereHas('orderDays', fn ($q) => $q->where('date', $targetDate))
            ->with([
                'client.mealTypes',
                'client.ingredientExclusions',
                'client.dishExclusions',
                'client.replacementBundles.items.originalIngredient',
                'ingredientExclusions',
                'menuPlan',
                'replacements.replacementProduct',
                'replacements.replacementDish',
                'projectData',
            ])
            ->get();

        if ($orders->isEmpty()) {
            return;
        }

        // Збираємо глобальні коментарі клієнтів — один блок зверху
        $this->clientComments = [];
        $seenClients = [];
        foreach ($orders as $order) {
            $cid = (int) $order->client?->id;
            if (!$cid || isset($seenClients[$cid])) continue;
            $comment = trim($order->client->production_comment ?? '');
            if (!empty($comment)) {
                $this->clientComments[] = [
                    'id'      => $cid,
                    'name'    => $order->client->name,
                    'project' => $order->projectData?->name ?? ucfirst($order->project ?? ''),
                    'calories'=> (int)($order->calories ?? 0),
                    'text'    => $comment,
                ];
                $seenClients[$cid] = true;
            }
        }

        // === ГРУПУЄМО ЗАМОВЛЕННЯ ПО ПЛАНАХ МЕНЮ ===
        $ordersByPlan = $orders->groupBy(fn ($o) => $o->effectiveMenuPlan()?->id ?? 0);

        foreach ($ordersByPlan as $planId => $planOrders) {
            $menuPlan = $planOrders->first()->effectiveMenuPlan();
            if (!$menuPlan) continue;

            $globalDay = $menuPlan->globalDayFor($targetDateObj);
            $menu = DailyMenu::where('menu_plan_id', $menuPlan->id)
                ->where('day_number', $globalDay)
                ->with([
                    'menuItems.dish.dishIngredients.ingredient',
                    'menuItems.dish.dishIngredients.childDish.dishIngredients.ingredient',
                    'menuItems.mealType'
                ])
                ->first();

            if (!$menu) {
                $this->missingPlans[] = [
                    'plan'          => $menuPlan,
                    'day_number'    => $globalDay,
                    'orders_count'  => $planOrders->count(),
                    'client_names'  => $planOrders->map(fn ($o) => $o->client?->name)->filter()->unique()->take(5)->values()->all(),
                ];
                continue;
            }

            $planTables = []; // окремий накопичувач рядків для цього плану

            foreach ($planOrders as $order) {
                $this->orderPlans[$order->id] = $this->calculateOrderPlan($order, $menu, $targetDate);
            }

            $orders = $planOrders; // alias для існуючого нижче коду

        $sortedMenuItems = $menu->menuItems->sortBy(fn ($i) => $i->mealType?->sort_order ?? 99);

        // Накопичувачі — у межах плану:
        // $replacementDishData: клієнти з повною замiною страви (dishExclusion + replacementDish),
        //                      агрегуються в окрему таблицю по страві-заміннику.
        // $customClientData:   клієнти, у яких є будь-яка заміна інгредієнта, force-approved
        //                      конфлікт або невирішений конфлікт (клієнт вилучив інгредієнт,
        //                      а заміни не оформили). Кожен такий клієнт отримує власну картку
        //                      з переліком того, що йому треба покласти в пакет — інакше кухня
        //                      виготовляє на N-M порцій, а фасувальник рахує на N, і на
        //                      останніх M клієнтах не вистачає їжі.
        $replacementDishData = [];
        $customClientData    = [];

        foreach ($sortedMenuItems as $mItem) {
            $dish = $mItem->dish;
            if (!$dish) continue;

            $tableData = [
                'meal' => $mItem->mealType->name ?? 'Інше',
                'dish_name' => $dish->name,
                'columns' => [],
                'rows' => [],
                'individual_notes' => [],
            ];

            foreach ($orders as $order) {
                if ($order->menu_type === 'individual') continue; // індивідуальні — окремо

                $plan = $this->orderPlans[$order->id] ?? null;
                if (!$plan) continue;

                $plannedWeight = $this->plannedDishWeight(
                    $plan['items'] ?? [],
                    (int)$dish->id,
                    (int)$mItem->meal_type_id
                );

                if ($plannedWeight === null) continue;

                $baseW     = (float)($dish->base_weight_g ?? 0);
                $dishScale = $baseW > 0 ? ((float)$plannedWeight / $baseW) : 0.0;

                // === КАСТОМНИЙ КЛІЄНТ (той самий предикат, що у виробничому листі) ===
                // Клієнт не бере участі в стандартній колонці. Далі — три взаємовиключні шляхи:
                //   (a) страва повністю замінена іншою → аккумулюємо у replacementDishData;
                //   (b) страва повністю виключена без заміни → клієнт пропускає її взагалі;
                //   (c) інгредієнтний свап / force-approved / невирішений конфлікт → окрема картка.
                if ($this->isCustomForDish($order, $dish)) {
                    // Порядок гілок узгоджений з ProductionReport::buildCustomCard:
                    // dishReplacement має пріоритет над dishExclusion.

                    // (a) є оформлена заміна страви — незалежно від dishExclusion,
                    // клієнт отримує саме страву-замінник (у виробничому це та ж лінія коду).
                    $dishReplacement = $order->replacements
                        ->where('dish_id', $dish->id)
                        ->whereNull('original_product_id')
                        ->where('force_approved', false)
                        ->first();

                    if ($dishReplacement && $dishReplacement->replacementDish) {
                        $repDish   = $dishReplacement->replacementDish;
                        $repDishId = $repDish->id;
                        $repBaseW  = (float)($repDish->base_weight_g ?? 0);
                        $repScale  = $repBaseW > 0 ? ((float)$plannedWeight / $repBaseW) : 0.0;

                        $colKey   = (string)(int)($order->calories ?? 0);
                        $projSlug = $order->project ?? 'none';
                        $projName = $order->projectData?->name ?? ucfirst($projSlug);

                        if (!isset($replacementDishData[$repDishId])) {
                            $replacementDishData[$repDishId] = [
                                'meal'             => $mItem->mealType->name ?? 'Інше',
                                'dish_name'        => '→ Заміна: ' . $repDish->name,
                                'dish_obj'         => $repDish,
                                'columns'          => [],
                                'rows'             => [],
                                'individual_notes' => [],
                            ];
                        }
                        if (!isset($replacementDishData[$repDishId]['columns'][$colKey])) {
                            $replacementDishData[$repDishId]['columns'][$colKey] = [
                                'count' => 0, 'sum_scale' => 0.0, 'projects' => [],
                            ];
                        }
                        $replacementDishData[$repDishId]['columns'][$colKey]['count']++;
                        $replacementDishData[$repDishId]['columns'][$colKey]['sum_scale'] += $repScale;
                        if (!isset($replacementDishData[$repDishId]['columns'][$colKey]['projects'][$projSlug])) {
                            $replacementDishData[$repDishId]['columns'][$colKey]['projects'][$projSlug] = ['name' => $projName, 'count' => 0];
                        }
                        $replacementDishData[$repDishId]['columns'][$colKey]['projects'][$projSlug]['count']++;
                        continue;
                    }

                    // force-approved на рівні страви = «конфлікт зафіксовано, але клієнт їсть як усі»,
                    // тому dishExclusion при цьому не блокує подачу — клієнту потрібна картка з
                    // оригінальним складом (те саме робить виробничий: dish_excluded=false у картці).
                    $dishForcedApproval = $order->replacements
                        ->where('dish_id', $dish->id)
                        ->whereNull('original_product_id')
                        ->where('force_approved', true)
                        ->first();

                    $hasDishExclusion = !$dishForcedApproval
                        && $order->client->dishExclusions->contains('id', $dish->id);

                    if ($hasDishExclusion) {
                        // (b) страва виключена без заміни — клієнт її не отримує
                        // (у виробничому такі картки помічені dish_excluded і НЕ списуються зі складу,
                        // див. ProductionReport::processStockDebiting line 820).
                        continue;
                    }

                    // (c) інгредієнтний свап / force-approved / невирішений конфлікт
                    $oid = $order->id;
                    if (!isset($customClientData[$oid])) {
                        $customClientData[$oid] = [
                            'is_individual'    => true,   // рендер тим самим блоком, що індивідуали
                            'is_custom_client' => true,   // блейд може розрізнити оформленням
                            'client_label'     => '#' . $order->client->id . ' ' . $order->client->name,
                            'calories'         => (int)($order->calories ?? 0),
                            'project'          => $order->projectData?->name ?? ucfirst($order->project ?? ''),
                            'meals'            => [],
                        ];
                    }
                    $customClientData[$oid]['meals'][] = [
                        'meal'      => $mItem->mealType->name ?? 'Інше',
                        'dish_name' => $dish->name,
                        'rows'      => $this->buildCustomClientRows($order, $dish, $dishScale),
                        'notes'     => $this->collectOrderNotes($order, $dish),
                    ];
                    continue;
                }

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

            if (empty($tableData['columns'])) {
                if (!empty($tableData['individual_notes'])) {
                    $planTables[] = $tableData;
                }
                continue;
            }

            ksort($tableData['columns']);

            foreach ($dish->dishIngredients as $di) {
                $name = $this->ingredientRowLabel($di);

                $netWeight = (float)($di->net_weight_g ?? 0);
                $cells = [];
                foreach ($tableData['columns'] as $key => $col) {
                    $count    = (int)($col['count'] ?? 1);
                    $sumScale = (float)($col['sum_scale'] ?? 0.0);

                    $onePortionScale = $count > 0 ? ($sumScale / $count) : 0;

                    $cells[$key] = round($netWeight * $onePortionScale);
                }

                $tableData['rows'][] = [
                    'original_name' => $name,
                    'cells' => $cells,
                ];
            }

            $planTables[] = $tableData;
        }

        // === ЗАМІННІ СТРАВИ — окремі таблиці для страв, якими повністю замінено оригінал ===
        foreach ($replacementDishData as $repDishId => $repData) {
            $repDish = $repData['dish_obj'];
            $repDish->loadMissing(
                'dishIngredients.ingredient',
                'dishIngredients.childDish.dishIngredients.ingredient'
            );

            ksort($repData['columns']);

            foreach ($repDish->dishIngredients as $di) {
                $name = $this->ingredientRowLabel($di);

                $netWeight = (float)($di->net_weight_g ?? 0);
                $cells = [];
                foreach ($repData['columns'] as $key => $col) {
                    $count           = (int)($col['count'] ?? 1);
                    $sumScale        = (float)($col['sum_scale'] ?? 0.0);
                    $onePortionScale = $count > 0 ? ($sumScale / $count) : 0;
                    $cells[$key]     = round($netWeight * $onePortionScale);
                }
                $repData['rows'][] = ['original_name' => $name, 'cells' => $cells];
            }

            unset($repData['dish_obj']);
            $planTables[] = $repData;
        }

        // === КАСТОМНІ КЛІЄНТИ (свапи інгредієнта / force-approved / невирішені конфлікти) ===
        // Кожен клієнт — окрема картка з усіма своїми стравами дня, аналогічно індивідуальним.
        foreach ($customClientData as $card) {
            $planTables[] = $card;
        }

        // === ІНДИВІДУАЛЬНІ КЛІЄНТИ цього плану ===
        $individualByOrder = [];

        foreach ($orders as $order) {
            if ($order->menu_type !== 'individual') continue;

            $plan = $this->orderPlans[$order->id] ?? null;
            if (!$plan || empty($plan['items'])) continue;

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

            foreach ($plan['items'] as $item) {
                $dish = \App\Models\Dish::with('dishIngredients.ingredient', 'dishIngredients.childDish')
                    ->find($item['dish_id']);
                if (!$dish) continue;

                $weight = (int)$item['weight'];
                $baseW  = (float)($dish->base_weight_g ?? 0);
                $scale  = $baseW > 0 ? $weight / $baseW : 0.0;

                $rows = [];
                foreach ($dish->dishIngredients as $di) {
                    $name = $this->ingredientRowLabel($di);
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
            $planTables[] = $clientData;
        }

            // Зберігаємо секцію цього плану
            if (!empty($planTables)) {
                $this->report[$menuPlan->id] = [
                    'plan'       => $menuPlan,
                    'day_number' => $globalDay,
                    'tables'     => $planTables,
                ];
            }
        } // кінець foreach($ordersByPlan)

        if (empty($this->report)) {
            $this->debugMessage = "Меню для жодного плану не знайдено на {$targetDateObj->format('d.m.Y')}";
        }
    }

    // =========================================================
    // 🔧 ДОПОМІЖНІ МЕТОДИ (ЛОГІКА ЗАМІН)
    // =========================================================

    private function collectOrderNotes(Order $order, $dish): array
    {
        $notes = [];
        if (!$order->client) return $notes;

        $clientMeta = [
            'id'           => $order->client->id,
            'name'         => $order->client->name,
            'project'      => $order->projectData?->name ?? ucfirst($order->project ?? ''),
            'project_slug' => $order->project ?? 'none',
            'calories'     => (int)($order->calories ?? 0),
        ];

        // 1. Виключення цілої страви — з урахуванням force-approved.
        if ($order->client->dishExclusions->contains('id', $dish->id)) {
            $dishRep = $order->replacements->where('dish_id', $dish->id)->whereNull('original_product_id')->first();
            if ($dishRep && $dishRep->replacementDish) {
                $notes[] = array_merge($clientMeta, ['text' => "Страву повністю замінено на «{$dishRep->replacementDish->name}»"]);
                return $notes;
            }
            if ($dishRep && $dishRep->force_approved) {
                // Конфлікт зафіксовано, але клієнт їсть як усі — інгредієнтні заміни ще можливі,
                // тому НЕ повертаємось, а продовжуємо перевіряти інгредієнти нижче.
            } else {
                $notes[] = array_merge($clientMeta, ['text' => "Страву повністю ВИКЛЮЧЕНО"]);
                return $notes;
            }
        }

        // 3. Виключення інгредієнтів (рекурсивно)
        $this->checkIngredientsForNotes($dish, $order, $dish->id, $clientMeta, $notes);

        return $notes;
    }

    private function checkIngredientsForNotes($dish, $order, $rootDishId, array $clientMeta, array &$notes): void
    {
        if (!$dish || !$dish->dishIngredients) return;

        foreach ($dish->dishIngredients as $di) {
            // Звичайний продукт
            if ($di->ingredient_id && $order->effectiveExcludedIngredients()->contains('id', $di->ingredient_id)) {
                $rep = $order->replacements->where('dish_id', $rootDishId)->where('original_product_id', $di->ingredient_id)->first();
                if ($rep && $rep->force_approved) {
                    // Одобрено — нічого не показуємо
                } elseif ($rep && $rep->replacementProduct) {
                    $notes[] = array_merge($clientMeta, ['text' => "«{$di->ingredient->name}» замінено на «{$rep->replacementProduct->name}»"]);
                } else {
                    $notes[] = array_merge($clientMeta, ['text' => "Без «{$di->ingredient->name}»"]);
                }
            }
            // Якщо це ПФ - йдемо вглиб
            if ($di->child_dish_id && $di->childDish) {
                $this->checkIngredientsForNotes($di->childDish, $order, $rootDishId, $clientMeta, $notes);
            }
        }
    }

    // =========================================================
    // 🔧 ДОПОМІЖНІ МЕТОДИ (РОЗРАХУНКИ)
    // =========================================================

    private function plannedDishWeight(array $items, int $dishId, int $mealTypeId): ?int
    {
        foreach ($items as $it) {
            if ((int)($it['dish_id'] ?? 0) === $dishId && (int)($it['meal_type_id'] ?? 0) === $mealTypeId) {
                return (int)($it['weight'] ?? 0);
            }
        }
        return null;
    }

}