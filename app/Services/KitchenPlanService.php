<?php

namespace App\Services;

use App\Models\DailyMenu;
use App\Models\MenuPlan;
use App\Models\Order;
use App\Models\Setting;
use App\Traits\CalculatesOrderPlan;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class KitchenPlanService
{
    use CalculatesOrderPlan;


    /**
     * Зібрати всі дані та згенерувати план через OpenAI.
     *
     * @param  string        $targetDate  Дата у форматі Y-m-d (завтрашній день)
     * @param  array<int, array{id:int, name:string, position:string}> $employees
     * @return array  Розібраний JSON від GPT
     */
    public function generate(string $targetDate, array $employees): array
    {
        $prompt = $this->buildPrompt($targetDate, $employees);

        $response = Http::withToken(config('services.openai.key'))
            ->timeout(120)
            ->post('https://api.openai.com/v1/chat/completions', [
                'model'       => 'gpt-4o-mini',
                'max_tokens'  => 4000,
                'temperature' => 0.4,
                'messages'    => [
                    [
                        'role'    => 'system',
                        'content' => 'Ти — Головний Технолог та Шеф-кухар мережі доставки їжі. '
                            . 'Ти складаєш детальний план роботи кухні з розбивкою по годинах та по кухарях. '
                            . 'Завжди плануй паралельні процеси — кухарі не мають простоювати. '
                            . 'Враховуй обмеження обладнання (плити, духовки). '
                            . 'Заміни клієнтів — обов\'язково вказуй в задачах і таймлайні. '
                            . 'Відповідь ТІЛЬКИ валідний JSON без markdown, без тексту до/після.',
                    ],
                    [
                        'role'    => 'user',
                        'content' => $prompt,
                    ],
                ],
            ]);

        if ($response->failed()) {
            throw new \RuntimeException('OpenAI API error: ' . $response->body());
        }

        $raw = trim($response->json('choices.0.message.content') ?? '{}');

        // Якщо GPT все ж обгорнув у markdown — прибираємо
        $raw = preg_replace('/^```json\s*/i', '', $raw);
        $raw = preg_replace('/\s*```$/', '', $raw);

        $decoded = json_decode($raw, true);

        if (!is_array($decoded)) {
            throw new \RuntimeException('GPT повернув невалідний JSON: ' . $raw);
        }

        return $decoded;
    }

    // -------------------------------------------------------------------------
    // Побудова промпту
    // -------------------------------------------------------------------------

    private function buildPrompt(string $targetDate, array $employees): string
    {
        $dateFormatted = Carbon::parse($targetDate)->format('d.m.Y');

        // 1. Формуємо список бригади з акцентом на роль
        $brigadeLines = collect($employees)->map(function ($e) {
            $posLabel = $this->translatePosition($e['position'] ?? '');
            return "- {$e['name']} (Роль: {$posLabel}) — відповідальний за свою зону та індивідуальні заміни в ній.";
        })->implode("\n");

        if (empty(trim($brigadeLines))) {
            $brigadeLines = "Бригада не вказана. Сплануй роботу для оптимальної кількості кухарів (3-4 особи).";
        }

        // 2. Отримуємо дані виробничого плану
        $dishesText = $this->collectDishesText($targetDate);

        // 3. Заміни
        $replacementsText = $this->collectReplacementsText($targetDate);

        // 4. Коментарі
        $commentsText = $this->collectProductionCommentsText($targetDate);

        return <<<PROMPT
Ти — Головний Технолог та Шеф-кухар мережі доставки. Твоє завдання — розписати ПОВНИЙ робочий день для кожного кухаря.
Дата: {$dateFormatted}. Робоча зміна: 08:00–16:00. Відправка замовлень: 15:30.

=== ОБЛАДНАННЯ (ОБМЕЖЕННЯ) ===
- Плити: 4 конфорки.
- Духовки: 2 секції (макс. 4 листи одночасно).
- Поверхні: обмежені, плануй паралельні процеси розумно.

=== БРИГАДА ===
{$brigadeLines}

=== МЕНЮ ТА ОБ'ЄМИ (ВИРОБНИЧИЙ ЛИСТ) ===
{$dishesText}

=== ІНДИВІДУАЛЬНІ ЗАМІНИ ТА АЛЕРГЕНИ ===
{$replacementsText}

=== ОСОБЛИВІ ПРИМІТКИ ===
{$commentsText}

=== АЛГОРИТМ ТВОГО МИСЛЕННЯ (ЛОГІКА ПЛАНУВАННЯ) ===
1. РОЗПОДІЛ ЗАДАЧ: Не пиши одну задачу на кухаря. Розпиши ЛАНЦЮЖОК: "Підготовка інгредієнтів -> Термічна обробка -> Збірка -> Пакування".
2. ПАРАЛЕЛЬНІСТЬ: Якщо страва тушкується 90 хв (пасивний процес), кухар у цей час МАЄ виконувати іншу роботу (нарізка, збірка салатів тощо). Не залишай кухарів без діла.
3. ПОКРИТТЯ МЕНЮ (КРИТИЧНО): Кожен прийом їжі з виробничого листа ОБОВ'ЯЗКОВО повинен мати задачі у бригаді. Якщо в меню є Сніданок, Перекус, Обід, Полуденок, Вечеря, Додаток — кожен з них МАЄ бути закритий задачами когось з кухарів. НЕ МОЖНА пропустити жоден прийом їжі!
4. ПРІОРИТЕТИ за часом:
   - 08:00-09:30: Запуск тривалих процесів (варити м'ясо, запікати) + Сніданки.
   - 09:30-12:00: Перекуси та Обід.
   - 12:00-14:30: Полуденок та Вечеря (обидва обов'язково!).
   - 14:30-15:30: Фінальне пакування, стікерування та КВК (Контроль Виконання Клієнта).
5. ЗАМІНИ: Якщо у клієнта заміна, вона має бути згадана в задачах кухаря (tasks) ТА в таймлайні (timeline).

=== ФОРМАТ ВІДПОВІДІ (ТІЛЬКИ JSON) ===
Поверни об'єкт JSON. Не використовуй markdown (```json), не пиши текст до або після JSON.

{
  "summary": {
    "optimal_brigade_size": 4,
    "bottlenecks": ["Конкретні ризики: наприклад, черга в духовку або багато замін у П1"],
    "start_immediately": ["Список страв, які мають бути на вогні рівно о 08:00"]
  },
  "brigade": [
    {
      "code": "П1",
      "role": "Зона (напр: Холодний цех / Сніданки)",
      "person": "Ім'я кухаря",
      "tasks": [
        "08:00 - Підготовка овочів для X (15 хв)",
        "08:15 - Збірка смузі для 41 порц., врахувати заміну для Артема (30 хв)"
      ],
      "replacements": ["Артем — нюанс", "Кирило — перевірка шліфованого рису"]
    }
  ],
  "timeline": [
    {
      "time": "08:00",
      "events": [
        { "who": "П1", "what": "Запуск смузі та підготовка овочів", "replacements": ["Артем"] },
        { "who": "П2", "what": "Старт тушкування індички (90 хв)", "replacements": null }
      ]
    }
  ],
  "critical_clients": [
    {
      "client": "Ім'я",
      "replacements_count": 2,
      "check_list": ["Перевірити відсутність молока у фрікасе", "Перевірити тип рису"],
      "priority": "high"
    }
  ]
}
PROMPT;
    }

    // -------------------------------------------------------------------------
    // Збір страв з виробничого плану
    // -------------------------------------------------------------------------

    private function collectDishesText(string $targetDate): string
    {
        $menu = $this->menuForDate($targetDate);

        if (!$menu) {
            return '(меню на цей день не знайдено)';
        }

        // Активні замовлення на цю дату
        $activeOrders = Order::feedingOn($targetDate)
            ->with(['client', 'replacements.replacementDish'])
            ->get();

        $totalOrders = $activeOrders->count();

        $lines = ["Загальна кількість клієнтів: {$totalOrders}", ''];

        $sortedItems = $menu->menuItems->sortBy(fn ($item) => $item->mealType?->sort_order ?? 99);

        // Заздалегідь рахуємо план кожного замовлення через calculateOrderPlan.
        // Це той самий трейт, який використовує ShoppingList / PackagingService /
        // ProductionReport / PrintController — тому кухня побачить РЕАЛЬНІ грамажі
        // (з урахуванням індивідуальних цілей КБЖУ клієнтів, якщо задані), а не
        // «base × count», яке раніше грубо ігнорувало навіть калораж замовлення.
        $perOrderPlan = []; // orderId => ['dish_id' => weight, ...]
        foreach ($activeOrders as $order) {
            $plan = $this->calculateOrderPlan($order, $menu, $targetDate);
            $map = [];
            foreach ($plan['items'] as $it) {
                $map[$it['dish_id']] = ($map[$it['dish_id']] ?? 0) + (int) $it['weight'];
            }
            $perOrderPlan[$order->id] = $map;
        }

        foreach ($sortedItems as $item) {
            if (!$item->dish) continue;

            $mealType = $item->mealType->name ?? 'Інше';
            $dish     = $item->dish;

            // Клієнти, які реально їдять цю страву в цей день (з урахуванням
            // виключень і замін), + справжній сумарний нетто.
            $count       = 0;
            $totalNettoG = 0;
            foreach ($activeOrders as $order) {
                $hasDishExclusion = $order->client->dishExclusions()
                    ->where('dish_id', $dish->id)->exists();
                if ($hasDishExclusion) {
                    $hasReplacement = $order->replacements
                        ->where('dish_id', $dish->id)
                        ->whereNotNull('replacement_dish_id')
                        ->isNotEmpty();
                    if (! $hasReplacement) continue;
                }
                $w = $perOrderPlan[$order->id][$dish->id] ?? null;
                if ($w === null) continue; // страва не потрапила в план цього клієнта
                $count++;
                $totalNettoG += $w;
            }

            if ($count === 0) continue;

            $line = "[{$mealType}] {$dish->name} — {$count} порц., ~{$totalNettoG} г нетто";

            // Повний склад з кількостями. Тепер сумарна вага інгредієнта =
            // per-portion netto × загальна вага страви / базова вага страви,
            // сумована по замовленнях. Так закупки/списання не розїжджаються
            // з тим, що бачить кухня.
            $baseW = max(1.0, (float)($dish->base_weight_g ?? 0));
            $scale = $totalNettoG / ($baseW * max(1, $count));
            $ingredientLines = $dish->dishIngredients
                ->filter(fn ($i) => ($i->net_weight_g ?? 0) > 0)
                ->sortByDesc('net_weight_g')
                ->map(function ($i) use ($count, $scale) {
                    $name       = $i->ingredient->name ?? null;
                    $perPortion = round((float) $i->net_weight_g * $scale, 1);
                    $total      = round($perPortion * $count);
                    return $name ? "    • {$name}: {$perPortion} г/порц. → {$total} г всього" : null;
                })
                ->filter()
                ->values();

            if ($ingredientLines->isNotEmpty()) {
                $line .= "\n" . $ingredientLines->implode("\n");
            }

            $lines[] = $line;
        }

        return implode("\n", $lines);
    }

    // -------------------------------------------------------------------------
    // Збір замін
    // -------------------------------------------------------------------------

    /**
     * Текстовий вигляд замін для промпту GPT.
     */
    private function collectReplacementsText(string $targetDate): string
    {
        $lines = [];

        foreach ($this->collectReplacements($targetDate) as $client) {
            foreach ($client['items'] as $item) {
                $icon = match ($item['type']) {
                    'force'     => '⚡',
                    'exclusion' => '❌',
                    default     => '🔄',
                };

                $lines[] = "{$icon} {$client['client']} {$item['text']}";
            }
        }

        return empty($lines)
            ? '(індивідуальних замін немає)'
            : implode("\n", $lines);
    }

    /**
     * Єдине джерело правди про індивідуальні заміни на дату — і для сторінки
     * кухні, і для промпту GPT. Раніше кожен збирав їх сам, і обидва бачили
     * тільки order_replacements, тобто заміни, заведені руками у виробничому
     * звіті. Виключення з картки клієнта до кухні не доїжджали взагалі.
     *
     * @return array<int, array{client: string, items: array<int, array{type: string, text: string}>}>
     */
    public function collectReplacements(string $targetDate): array
    {
        $activeOrders = Order::feedingOn($targetDate)
            ->with([
                'client',
                'client.dishExclusions',
                'client.ingredientExclusions',
                'client.replacementBundles.items.originalIngredient',
                'client.replacementBundles.items.replacementIngredient',
                'ingredientExclusions',
                'replacements.originalProduct',
                'replacements.replacementProduct',
                'replacements.dish',
                'replacements.replacementDish',
            ])
            ->get();

        $menu = $this->menuForDate($targetDate);

        // Сімейне замовлення — це батьківський раціон і дочірні на одного клієнта.
        // Менеджер відмічає заміну в одному з них, а страва готується та сама, тож
        // рішення збираємо по клієнту цілком. Інакше поруч стають «схвалено» і
        // «без» про той самий інгредієнт у тій самій страві.
        $decidedByClient = [];

        foreach ($activeOrders as $order) {
            $key = $this->clientKeyFor($order);

            $decidedByClient[$key] ??= ['ingredients' => [], 'dishes' => []];

            foreach ($order->replacements as $rep) {
                if ($rep->original_product_id) {
                    $decidedByClient[$key]['ingredients'][] = $rep->dish_id . ':' . $rep->original_product_id;
                }
                $decidedByClient[$key]['dishes'][] = (int) $rep->dish_id;
            }
        }

        $byClient = [];

        foreach ($activeOrders as $order) {
            $clientName = $order->client->name ?? "Замовлення #{$order->id}";
            $decided    = $decidedByClient[$this->clientKeyFor($order)];
            $items      = [];

            // Страви, які реально стоять у цього клієнта на цей день.
            $scheduledDishIds = $this->scheduledDishIdsFor($order, $menu, $targetDate);

            // 1. Рішення менеджера з виробничого звіту — вони головні і
            //    перекривають те, що написано в картці клієнта.
            foreach ($order->replacements as $rep) {
                // Заміни живуть у замовленні без прив'язки до дати, а меню
                // циклічне. Без цього фільтра кухня щодня читає вказівки до
                // страв, які сьогодні ніхто не готує, і серед них губляться
                // справжні. Якщо меню на день не знайшлось — не ховаємо нічого.
                if ($menu && ! in_array((int) $rep->dish_id, $scheduledDishIds, true)) continue;

                $dishName = $rep->dish->name ?? '?';
                $comment  = $rep->comment ? " — {$rep->comment}" : '';

                if ($rep->force_approved) {
                    $what = $rep->originalProduct->name ?? null;

                    $items[] = [
                        'type' => 'force',
                        'text' => $what
                            ? "[{$dishName}]: ПРИМУСОВО СХВАЛЕНО '{$what}' (залишається попри виключення клієнта){$comment}"
                            : "[{$dishName}]: страву ПРИМУСОВО СХВАЛЕНО (готуємо попри виключення клієнта){$comment}",
                    ];

                } elseif ($rep->replacementDish) {
                    $items[] = [
                        'type' => 'dish',
                        'text' => "замість '{$dishName}' → '{$rep->replacementDish->name}'{$comment}",
                    ];

                } elseif ($rep->replacementProduct && $rep->originalProduct) {
                    $items[] = [
                        'type' => 'ingredient',
                        'text' => "[{$dishName}]: замість '{$rep->originalProduct->name}' → '{$rep->replacementProduct->name}'{$comment}",
                    ];

                } elseif ($rep->originalProduct) {
                    $items[] = [
                        'type' => 'exclusion',
                        'text' => "[{$dishName}]: без '{$rep->originalProduct->name}'{$comment}",
                    ];
                }
            }

            // 2. Виключення продуктів із картки клієнта та з самого замовлення,
            //    на які менеджер ще нічого не вирішив. Саме їх кухня не бачила:
            //    анкету правлять посеред періоду, а order_replacements
            //    з'являються тільки вручну.
            if ($menu) {
                $excluded    = $order->effectiveExcludedIngredients();
                $excludedIds = $excluded->pluck('id')->map(fn ($id) => (int) $id)->all();
                $refusedDishIds = ($order->client?->dishExclusions ?? collect())
                    ->pluck('id')->map(fn ($id) => (int) $id)->all();

                foreach ($this->planDishesFor($order, $menu, $targetDate) as $dish) {
                    // Страву відмінили цілком — розбір її складу кухні вже не
                    // потрібен. Хіба що менеджер вирішив її все-таки готувати.
                    if (in_array((int) $dish->id, $refusedDishIds, true)
                        && ! in_array((int) $dish->id, $decided['dishes'], true)) {
                        continue;
                    }

                    foreach ($excluded as $ingredient) {
                        if (in_array($dish->id . ':' . $ingredient->id, $decided['ingredients'], true)) continue;
                        if (! $this->dishContainsIngredient($dish, (int) $ingredient->id)) continue;

                        $suggested = $this->bundleReplacementNameFor($order, (int) $ingredient->id, $excludedIds);

                        $items[] = $suggested
                            ? [
                                'type' => 'ingredient',
                                'text' => "[{$dish->name}]: замість '{$ingredient->name}' → '{$suggested}' (шаблон з картки клієнта)",
                            ]
                            : [
                                'type' => 'exclusion',
                                'text' => "[{$dish->name}]: без '{$ingredient->name}' (виключення з картки клієнта)",
                            ];
                    }
                }
            }

            // 3. Відмови від цілих страв — тільки ті, що реально стоять на цей
            //    день. У картках бувають десятки відмов (переважно
            //    напівфабрикати), і без фільтра вони затоплюють список шумом.
            foreach (($order->client?->dishExclusions ?? collect()) as $excludedDish) {
                if (! in_array((int) $excludedDish->id, $scheduledDishIds, true)) continue;

                // Менеджер уже щось вирішив по цій страві — замінив або схвалив.
                if (in_array((int) $excludedDish->id, $decided['dishes'], true)) continue;

                $items[] = [
                    'type' => 'exclusion',
                    'text' => "повністю відмовляється від '{$excludedDish->name}' — потрібна заміна",
                ];
            }

            if (empty($items)) continue;

            // У сімейних замовленнях на одного клієнта припадає кілька раціонів,
            // тож та сама вказівка приходить двічі. Кухні від повтору нічого не додається.
            $existing = $byClient[$clientName]['items'] ?? [];

            foreach ($items as $item) {
                $isDuplicate = collect($existing)->contains(fn ($e) => $e['text'] === $item['text']);
                if (! $isDuplicate) $existing[] = $item;
            }

            $byClient[$clientName] = ['client' => $clientName, 'items' => $existing];
        }

        return array_values($byClient);
    }

    /**
     * Ключ клієнта: сімейні раціони одного клієнта мають ділити рішення менеджера.
     */
    private function clientKeyFor(Order $order): string
    {
        return $order->client_id ? 'client-' . $order->client_id : 'order-' . $order->id;
    }

    /**
     * Меню дефолтного плану на дату, з усіма зв'язками, які потрібні
     * і для грамажів, і для пошуку виключених інгредієнтів у складі страв.
     */
    private function menuForDate(string $targetDate): ?DailyMenu
    {
        // TODO (multi-plan): зараз бере меню з дефолтного плану.
        $defaultPlan = \App\Models\MenuPlan::default();
        if (!$defaultPlan) return null;

        $dayNumber = $defaultPlan->globalDayFor(Carbon::parse($targetDate));

        return DailyMenu::where('menu_plan_id', $defaultPlan->id)
            ->where('day_number', $dayNumber)
            ->with([
                'menuItems.dish.dishIngredients.ingredient',
                'menuItems.dish.dishIngredients.childDish.dishIngredients.ingredient',
                'menuItems.mealType',
            ])
            ->first();
    }

    /**
     * Страви, які реально потрапили в план цього замовлення на дату
     * (для індивідуальних меню — персональні, а не циклічні).
     */
    private function planDishesFor(Order $order, DailyMenu $menu, string $targetDate): array
    {
        $plan = $this->calculateOrderPlan($order, $menu, $targetDate);

        $dishIds = collect($plan['items'])->pluck('dish_id')->unique()->filter()->all();
        if (empty($dishIds)) return [];

        $fromMenu = $menu->menuItems
            ->map(fn ($mi) => $mi->dish)
            ->filter()
            ->keyBy('id');

        $missing = array_values(array_diff($dishIds, $fromMenu->keys()->all()));

        if ($missing) {
            // Персональні страви індивідуальних клієнтів не лежать у циклічному меню.
            $extra = \App\Models\Dish::whereIn('id', $missing)
                ->with([
                    'dishIngredients.ingredient',
                    'dishIngredients.childDish.dishIngredients.ingredient',
                ])
                ->get()
                ->keyBy('id');

            $fromMenu = $fromMenu->union($extra);
        }

        return $fromMenu->only($dishIds)->values()->all();
    }

    /**
     * Страви, які стоять у клієнта на цю дату: циклічне меню дня, а для
     * індивідуальних замовлень — призначені персональні страви. Береться з меню,
     * а не з розрахованого плану, щоб виключена страва не зникла з переліку
     * ще до того, як кухня про неї дізнається.
     */
    private function scheduledDishIdsFor(Order $order, ?DailyMenu $menu, string $targetDate): array
    {
        $ids = $menu
            ? $menu->menuItems->map(fn ($mi) => $mi->dish?->id)->filter()->all()
            : [];

        if ($order->menu_type === 'individual') {
            $personal = $order->personalDishes()
                ->where('date', $targetDate)
                ->pluck('dish_id')
                ->all();

            $ids = array_merge($ids, $personal);
        }

        return array_values(array_unique(array_map('intval', $ids)));
    }

    /**
     * Чи є інгредієнт у складі страви — з урахуванням вкладених напівфабрикатів.
     */
    private function dishContainsIngredient($dish, int $ingredientId): bool
    {
        foreach ($dish->dishIngredients as $di) {
            if ((int) $di->ingredient_id === $ingredientId) return true;

            if ($di->childDish) {
                foreach ($di->childDish->dishIngredients as $sub) {
                    if ((int) $sub->ingredient_id === $ingredientId) return true;
                }
            }
        }

        return false;
    }

    /**
     * Назва заміни з прив'язаного до клієнта шаблону, якщо вона там є.
     */
    private function bundleReplacementNameFor(Order $order, int $ingredientId, array $excludedIds = []): ?string
    {
        foreach (($order->client?->replacementBundles ?? collect()) as $bundle) {
            foreach ($bundle->items as $item) {
                if ((int) $item->original_ingredient_id !== $ingredientId) continue;
                if (! $item->replacementIngredient) continue;

                // Шаблон міг застаріти: інгредієнт-заміна теж може бути в
                // виключеннях клієнта. Тоді це не підказка, а пастка.
                if (in_array((int) $item->replacementIngredient->id, $excludedIds, true)) continue;

                return $item->replacementIngredient->name;
            }
        }

        return null;
    }

    // -------------------------------------------------------------------------
    // Збір коментарів виробництва
    // -------------------------------------------------------------------------

    private function collectProductionCommentsText(string $targetDate): string
    {
        $orders = Order::feedingOn($targetDate)
            ->with('client')
            ->get();

        $lines = [];

        foreach ($orders as $order) {
            $comment = trim($order->client->production_comment ?? '');
            if ($comment !== '') {
                $lines[] = "• {$order->client->name}: {$comment}";
            }
        }

        return empty($lines)
            ? '(коментарів немає)'
            : implode("\n", $lines);
    }

    // -------------------------------------------------------------------------
    // Хелпери
    // -------------------------------------------------------------------------

    private function translatePosition(string $position): string
    {
        return match ($position) {
            'cook'    => 'Кухар',
            'packer'  => 'Пакувальник',
            'courier' => 'Кур\'єр',
            'manager' => 'Менеджер',
            'admin'   => 'Адміністратор',
            default   => $position,
        };
    }
}
