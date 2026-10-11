<?php

namespace App\Services\Kitchen;

use App\Models\Dish;
use App\Models\Order;
use App\Traits\CalculatesOrderPlan;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Індивідуальні клієнти у фасувальному листі — за погодженим 11.10 стандартом
 * («Фасування_дд.мм» сесії індивідуальних), поруч із циклічним меню:
 *
 *   - угорі реєстр: № клієнта, доставка (🌙 вечірні першими, час), ⛔ що не можна;
 *   - клієнт з тією самою стравою, що в циклічному, — колонкою в таблиці
 *     циклічної страви, навіть із замінами: заміна чи «НЕ класти» — червоною клітинкою;
 *   - решта страв прийому — блоком одразу під таблицею: таблиця на страву,
 *     складові × клієнти, «Разом»; власні страви клієнта — з «≈»;
 *   - шапка колонки: № клієнта, ім'я, 🌙/☀ час доставки, вага порції;
 *   - пакувальні примітки 📦 — з примітки до страви в персональному меню.
 */
class IndividualPacking
{
    use CalculatesOrderPlan;

    /** @var array<int, array> за order_id: n, name, eve, time, kcal, order */
    private array $clients = [];

    private string $date;

    public function __construct(Collection $orders, string $date)
    {
        $this->date = $date;
        $withMenu = DB::table('order_day_dishes')->whereDate('date', $date)
            ->whereIn('order_id', $orders->where('menu_type', 'individual')->pluck('id'))
            ->distinct()->pluck('order_id')->flip();

        $list = $orders->filter(fn (Order $o) => $o->menu_type === 'individual' && isset($withMenu[$o->id]))
            ->sortBy(fn (Order $o) => [$this->isEvening($o) ? 0 : 1, (string) $o->delivery_time, (string) $o->client?->name])
            ->values();

        $twins = $list->groupBy(fn (Order $o) => $o->client_id.':'.$o->calories)->filter(fn ($g) => $g->count() > 1)->keys()->all();
        foreach ($list as $i => $o) {
            $this->clients[$o->id] = [
                'n'     => $i + 1,
                'name'  => $this->shortName($o).(in_array($o->client_id.':'.$o->calories, $twins) ? ' (зам. '.$o->id.')' : ''),
                'eve'   => $this->isEvening($o),
                'time'  => (string) $o->delivery_time,
                'kcal'  => (int) $o->calories,
                'order' => $o,
            ];
        }

    }

    /** № клієнта, коротке ім'я, 🌙/☀, час доставки — для підписів у готувальному. */
    public function client(int $orderId): ?array
    {
        $c = $this->clients[$orderId] ?? null;

        return $c ? ['n' => $c['n'], 'name' => $c['name'], 'eve' => $c['eve'], 'time' => mb_substr($c['time'], 0, 5), 'kcal' => $c['kcal']] : null;
    }

    /** «№3 Лазутіна Олена 🌙 18:00» */
    public function label(int $orderId): ?string
    {
        $c = $this->client($orderId);

        return $c ? '№'.$c['n'].' '.$c['name'].' · '.$c['kcal'].' '.($c['eve'] ? '🌙' : '☀').' '.$c['time'] : null;
    }

    /** Картка заміни циклічної страви: «не їсть» клієнта; для індивідуального — № і примітка. */
    public function decorateCard(array $card, Order $order, int $dishId): array
    {
        // Повний короткий перелік «не їсть» — щоб, замінюючи, кухня не поклала заборонене.
        $card['no_eat'] = \App\Support\NoEat::summary($order);

        if ($label = $this->label($order->id)) {
            $card['client_name'] = $label;
            $card['ind_note'] = $order->personalDishes()->whereDate('date', $this->date)->where('dish_id', $dishId)->value('cooking_note');
        }

        return $card;
    }

    /** Шапка картки індивідуальної страви в готувальному. */
    public function card(Order $order, array $item, Dish $dish): array
    {
        $c = $this->client($order->id) ?? ['n' => 0, 'name' => $order->client?->name, 'eve' => false, 'time' => '', 'kcal' => (int) $order->calories];

        return $c + [
            'meal'         => $item['meal'],
            'dish_name'    => $dish->name,
            'is_own'       => $this->isOwn($dish),
            'weight'       => (int) $item['weight'],
            'recipe'       => $dish->description,
            'cooking_note' => $item['cooking_note'] ?? null,
            'diet_label'   => $order->client?->diet?->label(),
        ];
    }

    /**
     * Страви довідника, які індивідуальні їдять, а в сьогоднішньому циклі їх немає:
     * кухня готує їх загальною партією, як циклічні. Повертає «пункти меню»
     * (dish, meal_type_id, mealType) для того самого циклу, що й циклічні страви.
     */
    public function catalogItems(Collection $planOrders, array $plans, Collection $menuItems): Collection
    {
        $cycleKeys = $menuItems->filter(fn ($mi) => $mi->dish)->mapWithKeys(fn ($mi) => [$mi->dish_id.':'.$mi->meal_type_id => true])->all();
        $want = [];
        foreach ($planOrders as $o) {
            if ($o->menu_type !== 'individual') continue;
            foreach ($plans[$o->id]['items'] ?? [] as $it) {
                $key = $it['dish_id'].':'.$it['meal_type_id'];
                if (!isset($cycleKeys[$key])) $want[$key] = [(int) $it['dish_id'], (int) $it['meal_type_id']];
            }
        }
        if (!$want) return collect();

        $dishes = Dish::with([
            'dishIngredients.ingredient.allergens',
            'dishIngredients.childDish.dishIngredients.ingredient.allergens',
        ])->whereIn('id', array_column($want, 0))->get()->keyBy('id');
        $mealTypes = \App\Models\MealType::whereIn('id', array_column($want, 1))->get()->keyBy('id');

        return collect($want)
            ->filter(fn ($w) => ($d = $dishes->get($w[0])) && !$this->isOwn($d))
            ->map(fn ($w) => (object) ['dish' => $dishes->get($w[0]), 'dish_id' => $w[0], 'meal_type_id' => $w[1], 'mealType' => $mealTypes->get($w[1])])
            ->values();
    }

    /**
     * Аркуші готування: на кожного клієнта його власні страви («Інд · …») і циклові
     * страви з його замінами ($separate), решта — «з загальної партії». $components(Dish, float $scale, Order) → [components, totals].
     */
    public function cookPages(Collection $planOrders, array $plans, array $separate, callable $components): array
    {
        $pages = [];
        foreach ($planOrders as $o) {
            if (!isset($this->clients[$o->id]) || empty($plans[$o->id]['items'])) continue;

            $own = [];
            $batch = [];
            foreach ($plans[$o->id]['items'] as $it) {
                $dish = Dish::with(['dishIngredients.ingredient', 'dishIngredients.childDish.dishIngredients.ingredient'])->find($it['dish_id']);
                if (!$dish) continue;
                // Циклова страва із замінами клієнта готується окремо — теж на аркуші.
                $withSwaps = isset($separate[$o->id.':'.$dish->id]);
                if (!$this->isOwn($dish) && !$withSwaps) {
                    $batch[] = $it['meal'].' — '.$dish->name;
                    continue;
                }
                $baseW = (float) ($dish->base_weight_g ?? 0);
                [$comps, $totals] = $components($dish, $baseW > 0 ? $it['weight'] / $baseW : 0.0, $o);
                [$tech, $pack] = $this->technology((string) $dish->description);
                $own[] = [
                    'meal'       => $it['meal'],
                    'dish_name'  => trim(preg_replace('/^Інд\s*·\s*/u', '', $dish->name)),
                    'with_swaps' => $withSwaps,
                    'weight'     => (int) $it['weight'],
                    'components' => $comps,
                    'totals'     => $totals,
                    'tech'       => $tech,
                    // Пакування з техкарти; примітка до страви — лише якщо в техкарті його немає.
                    'pack'       => rtrim($pack ?: (string) $this->packNote($it['cooking_note'] ?? null), '. '),
                ];
            }

            $c = $this->clients[$o->id];
            $reg = collect($this->registry())->firstWhere('n', $c['n']);
            $pages[] = [
                'n' => $c['n'], 'name' => $c['name'], 'kcal' => $c['kcal'], 'eve' => $c['eve'], 'time' => $c['time'],
                'stop'  => $reg['stop'] ?? '',
                'diet'  => $reg['diet'] ?? null,
                'rules' => $reg['diet_rules'] ?? '',
                // Коментар до виробництва, крім службових «Індивідуальний раціон/клієнт».
                'memo'  => preg_match('/^індивідуальн\w*\s+(раціон|клієнт)\.?$/iu', $memo = trim((string) $o->client?->production_comment)) ? '' : $memo,
                'own'   => $own,
                'batch' => $batch,
            ];
        }
        usort($pages, fn ($a, $b) => $a['n'] <=> $b['n']);

        return $pages;
    }

    /** «… Технологія: 1. … 2. … Пакування: …» → [кроки, пакування]. */
    private function technology(string $description): array
    {
        $text = trim(strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>'], "\n", $description)));
        $pack = '';
        if (preg_match('/Пакування:\s*(.+)$/su', $text, $m)) {
            $pack = trim($m[1]);
            $text = trim(mb_substr($text, 0, mb_strpos($text, $m[0])));
        }
        if (($p = mb_stripos($text, 'Технологія:')) !== false) {
            $text = trim(mb_substr($text, $p + mb_strlen('Технологія:')));
        }
        $steps = array_values(array_filter(array_map('trim', preg_split('/\s*(?=\b\d{1,2}\.\s)/u', $text))));

        return [$steps, $pack];
    }

    /** Реєстр угорі листа: №, клієнт, доставка, ⛔ не можна (алергії, виключені продукти й страви). */
    public function registry(): array
    {
        return array_values(array_map(function ($c) {
            $o = $c['order'];
            $stop = \App\Support\NoEat::summary($o);
            $diet = $o->client?->diet;

            return ['n' => $c['n'], 'name' => $c['name'].' · '.$c['kcal'], 'eve' => $c['eve'], 'time' => $c['time'], 'stop' => $stop,
                'diet' => $diet?->label(),
                'diet_rules' => trim(implode(' ', array_filter([
                    $diet?->cooking_methods ? 'Спосіб приготування: '.$diet->cooking_methods.'.' : null,
                    $diet?->kitchen_note ? 'Правила кухні: '.$diet->kitchen_note : null,
                ])))];
        }, $this->clients));
    }

    /**
     * @param  array  $tables  таблиці плану (циклічні мають dish_id і meal_type_id)
     * @param  array  $plans   [order_id => calculateOrderPlan()]
     */
    public function arrange(array $tables, Collection $orders, Collection $menuItems, array $plans): array
    {
        $individuals = $orders->filter(fn (Order $o) => isset($this->clients[$o->id]) && !empty($plans[$o->id]['items']))
            ->sortBy(fn (Order $o) => $this->clients[$o->id]['n'])->values();
        if ($individuals->isEmpty()) {
            return $tables;
        }

        $cycleKeys = $menuItems->filter(fn ($mi) => $mi->dish)
            ->mapWithKeys(fn ($mi) => [$mi->dish_id.':'.$mi->meal_type_id => $mi])->all();
        $mealOrder = $menuItems->mapWithKeys(fn ($mi) => [$mi->meal_type_id => $mi->mealType?->sort_order ?? 99])->all();

        $blocks = [];
        foreach ($individuals as $order) {
            foreach ($plans[$order->id]['items'] as $item) {
                $dish = Dish::with(['dishIngredients.ingredient', 'dishIngredients.childDish.dishIngredients.ingredient'])->find($item['dish_id']);
                if (!$dish) continue;

                $weight = (int) $item['weight'];
                $col    = $this->column($order, $weight);
                $rows   = $this->rowsFor($order, $dish, $weight, (string) $item['meal'], $this->noteSwaps($item['cooking_note'] ?? null));
                $pack   = $this->packNote($item['cooking_note'] ?? null);
                $key    = $dish->id.':'.$item['meal_type_id'];

                // Та сама страва, що в циклічному, — у таблицю циклічного, з червоними клітинками.
                if (isset($cycleKeys[$key])) {
                    $tables = $this->addColumn($tables, $cycleKeys[$key], $dish, $order, $col, $rows, $pack);
                    continue;
                }

                $mt = (int) $item['meal_type_id'];
                $blocks[$mt] ??= ['meal' => $item['meal'], 'meal_type_id' => $mt, 'dishes' => []];
                $blocks[$mt]['dishes'][$dish->id] ??= [
                    'dish_name' => $dish->name, 'is_cycle' => !$this->isOwn($dish),
                    'clients' => [], 'rows' => [], 'pack' => [],
                ];
                $d = &$blocks[$mt]['dishes'][$dish->id];
                $c = count($d['clients']);
                $d['clients'][] = $col;
                foreach ($rows as $r) {
                    if ($r['g'] < 0.5 && !$r['skip'] && $r['note'] === '') continue;
                    $d['rows'][$r['name']] ??= ['name' => $r['name'], 'cells' => []];
                    $d['rows'][$r['name']]['cells'][$c] = $r;
                }
                if ($pack) $d['pack'][] = ['n' => $col['n'], 'text' => $pack];
                unset($d);
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
                uasort($blocks, fn ($a, $b) => ($mealOrder[$a['meal_type_id']] ?? 99) <=> ($mealOrder[$b['meal_type_id']] ?? 99));
                foreach ($blocks as $b) $out[] = $this->block($b);
                $blocks = [];
            }
        }
        foreach ($blocks as $b) $out[] = $this->block($b);

        return $out;
    }

    /**
     * Складові страви для одного клієнта — верхній рівень техкарти, як у фасувальному.
     * Заміна/виключення продукту — у клітинці продукту; усередині ПФ — приміткою на рядку ПФ.
     * pos — номер рядка техкарти (для колонки в циклічній таблиці).
     */
    private function rowsFor(Order $order, Dish $dish, int $weight, string $meal, array $noteSwaps = []): array
    {
        $approx = $this->isOwn($dish);
        if (str_contains(mb_strtolower($meal), 'додаток') || $dish->dishIngredients->isEmpty()) {
            return [['pos' => 0, 'name' => $dish->dishIngredients->isEmpty() ? 'вся страва' : $dish->name,
                'g' => $weight, 'note' => '', 'skip' => false, 'approx' => $approx]];
        }

        $baseW = (float) $dish->base_weight_g ?: max(1.0, (float) $dish->dishIngredients->sum('net_weight_g'));
        $k = $weight / $baseW;
        $excluded = array_flip($order->effectiveExcludedIngredientIds());
        $reps = $order->replacements->where('dish_id', $dish->id)->where('force_approved', false)
            ->whereNotNull('original_product_id')->keyBy('original_product_id');

        $out = [];
        foreach ($dish->dishIngredients->values() as $pos => $di) {
            $row = ['pos' => $pos, 'name' => $this->ingredientRowLabel($di), 'g' => (float) $di->net_weight_g * $k,
                'note' => '', 'skip' => false, 'approx' => $approx];
            if ($di->type === 'pf' && $di->childDish) {
                $notes = [];
                foreach ($this->closure($di->childDish) as $ingId => $ingName) {
                    if ($to = $noteSwaps[mb_strtolower(trim($ingName))] ?? null) {
                        $notes[] = mb_strtolower($ingName).' → '.mb_strtolower($to);
                    } elseif ($rep = $reps[$ingId] ?? null) {
                        $notes[] = mb_strtolower($ingName).' → '.mb_strtolower((string) $rep->replacementProduct?->name);
                    } elseif (isset($excluded[$ingId])) {
                        $notes[] = 'без '.mb_strtolower($ingName);
                    }
                }
                $row['note'] = implode('; ', array_unique($notes));
            } elseif ($di->ingredient_id) {
                if ($to = $noteSwaps[mb_strtolower(trim((string) $di->ingredient?->name))] ?? null) {
                    $row['note'] = '→ '.$to;
                } elseif ($rep = $reps[$di->ingredient_id] ?? null) {
                    $row['note'] = '→ '.$rep->replacementProduct?->name;
                } elseif (isset($excluded[$di->ingredient_id])) {
                    $row['skip'] = true;
                }
            }
            $out[] = $row;
        }

        return $out;
    }

    /** Усі продукти всередині ПФ (рекурсивно): [id => назва]. */
    private function closure(Dish $pf, array $seen = []): array
    {
        $out = [];
        $pf->loadMissing('dishIngredients.ingredient', 'dishIngredients.childDish');
        foreach ($pf->dishIngredients as $di) {
            if ($di->type === 'pf' && $di->childDish) {
                if (isset($seen[$di->child_dish_id])) continue;
                $seen[$di->child_dish_id] = true;
                $out += $this->closure($di->childDish, $seen);
            } elseif ($di->ingredient_id && $di->ingredient) {
                $out[$di->ingredient_id] = $di->ingredient->name;
            }
        }

        return $out;
    }

    private function addColumn(array $tables, $menuItem, Dish $dish, Order $order, array $col, array $rows, ?string $pack): array
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
                'columns' => [], 'rows' => [], 'individual_notes' => []];
            $idx = array_key_last($tables);
        }
        if (empty($tables[$idx]['rows'])) {
            $tables[$idx]['rows'] = $dish->dishIngredients->map(fn ($di) => ['original_name' => $this->ingredientRowLabel($di), 'cells' => []])->all();
        }

        $colKey = 'ind-'.$order->id;
        $slug   = $order->project ?? 'none';
        $tables[$idx]['columns'][$colKey] = [
            'label'        => '№'.$col['n'].' '.$col['name'],
            'count'        => 1,
            'sum_scale'    => 0,
            'custom_count' => 0,
            'ind'          => $col,
            'projects'     => [$slug => ['name' => $order->projectData?->name ?? ucfirst($slug), 'count' => 1, 'custom_count' => 0]],
        ];
        // Рядки циклічної таблиці побудовані в тому ж порядку, що dishIngredients.
        foreach ($rows as $r) {
            if (isset($tables[$idx]['rows'][$r['pos']])) {
                $tables[$idx]['rows'][$r['pos']]['cells'][$colKey] = ['val' => round($r['g']), 'note' => $r['note'], 'skip' => $r['skip']];
            }
        }
        if ($pack) {
            $tables[$idx]['pack'][] = ['n' => $col['n'], 'text' => $pack];
        }

        return $tables;
    }

    /**
     * Звичайний клієнт із замінами в циклічній страві — колонкою «#155 Суходольська · 1800»
     * у її таблиці: грами саме йому, заміни й «НЕ класти» червоними клітинками.
     */
    public function customColumn(array $tableData, Order $order, Dish $dish, int $weight): array
    {
        $cells = [];
        foreach ($this->rowsFor($order, $dish, $weight, '') as $r) {
            $cells[$r['pos']] = ['val' => round($r['g']), 'note' => $r['note'], 'skip' => $r['skip']];
        }
        $slug = $order->project ?? 'none';
        $tableData['columns']['cust-'.$order->id] = [
            'label' => '#'.$order->client?->id.' '.$this->shortName($order), 'count' => 1, 'sum_scale' => 0, 'custom_count' => 0,
            'ind'   => ['n' => null, 'name' => '#'.$order->client?->id.' '.$this->shortName($order).' · '.(int) $order->calories,
                'eve' => $this->isEvening($order), 'time' => '', 'weight' => $weight, 'cust' => true],
            'cells' => $cells,
            'projects' => [$slug => ['name' => $order->projectData?->name ?? ucfirst($slug), 'count' => 1, 'custom_count' => 0]],
        ];

        return $tableData;
    }

    private function block(array $b): array
    {
        return [
            'is_ind_block' => true,
            'meal'         => $b['meal'],
            'dishes'       => array_map(fn ($d) => ['rows' => array_values($d['rows'])] + $d, array_values($b['dishes'])),
        ];
    }

    private function column(Order $order, int $weight): array
    {
        $c = $this->clients[$order->id];

        return ['n' => $c['n'], 'name' => $c['name'], 'eve' => $c['eve'], 'time' => mb_substr($c['time'], 0, 5), 'weight' => $weight];
    }

    /**
     * Заміни з примітки до страви («індичка (філе) → Лосось з/м; без: цибуля») — агент
     * пише їх туди, коли заміни немає в оформлених: [назва продукту в нижньому регістрі => на що].
     */
    private function noteSwaps(?string $note): array
    {
        $out = [];
        foreach (explode(';', (string) $note) as $part) {
            if (str_contains($part, '→')) {
                [$from, $to] = array_map('trim', explode('→', $part, 2));
                if ($from !== '' && $to !== '') $out[mb_strtolower($from)] = $to;
            }
        }

        return $out;
    }

    /** Примітка до страви без замін (заміни вже в клітинках): «білкова добавка, окремий контейнер». */
    private function packNote(?string $note): ?string
    {
        $parts = array_filter(array_map('trim', explode(';', (string) $note)),
            fn ($p) => $p !== '' && !str_starts_with(mb_strtolower($p), 'без:') && !str_contains($p, '→'));

        return $parts ? implode('; ', $parts) : null;
    }

    private function shortName(Order $order): string
    {
        $name = trim(preg_replace('/\s*\(.*?\)\s*/u', ' ', (string) $order->client?->name));

        return implode(' ', array_slice(preg_split('/\s+/u', $name), 0, 2));
    }

    /** Власна страва клієнта — створена під нього («Інд · …»), на відміну від страв довідника. */
    private function isOwn(Dish $dish): bool
    {
        return str_starts_with(trim($dish->name), 'Інд');
    }

    private function isEvening(Order $order): bool
    {
        return str_contains((string) $order->schedule_type, 'evening');
    }
}
