<?php

namespace App\Services\Agents\Runtime;

use App\Models\AiAgentTask;
use App\Models\ClientDietProfile;
use App\Models\DailyMenu;
use App\Models\Dish;
use App\Models\IngredientGroup;
use App\Models\Order;
use App\Services\Agents\DishCatalog;
use App\Services\Agents\IndividualWeekMenu;
use App\Services\Agents\MenuGuard;
use App\Services\Agents\ProposalDesk;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Інструменти агента «Меню» на сервері: ті самі межі, що в API agents/v1 —
 * читати, перевіряти, подавати пропозиції, питати менеджера. У бойові дані не пише.
 * Усе — лише в межах замовлення задачі.
 */
class MenuTools implements AgentTools
{
    public function __construct(private AiAgentTask $task) {}

    public function definitions(): array
    {
        $menuSchema = [
            'type' => 'object',
            'description' => 'Payload individual_week_menu (docs/ai-agents-api.md): order_id підставляється сам',
            'properties' => [
                'replace_days' => ['type' => 'boolean'],
                'repeat_ok' => ['type' => 'array', 'items' => ['type' => ['integer', 'string']], 'description' => 'id страв або ref нових, які клієнт сам просить повторювати'],
                'new_dishes' => ['type' => 'array', 'items' => ['type' => 'object']],
                'days' => ['type' => 'array', 'items' => ['type' => 'object']],
            ],
            'required' => ['days'],
        ];

        return [
            $this->tool('task_context', 'Усе про задачу: клієнт, замовлення, цілі КБЖВ, прийоми клієнта, дні доставки в періоді, виключення (продукти, групи, страви), профіль харчування, бриф, страви циклу на ці дати, вже записане персональне меню, попередні пропозиції й коментарі людей. Викликати першим.', ['type' => 'object', 'properties' => new \stdClass]),
            $this->tool('search_dishes', 'Пошук готових страв у довіднику з КБЖВ на 100 г, конфліктами з виключеннями клієнта й тим, коли страву вже давали клієнту за 28 днів. Спершу збирайте меню з наявних страв.', [
                'type' => 'object',
                'properties' => [
                    'q' => ['type' => 'string', 'description' => 'частина назви'],
                    'ingredient' => ['type' => 'string', 'description' => 'продукт у складі'],
                    'kind' => ['type' => 'string', 'enum' => ['all', 'cycle', 'individual']],
                    'limit' => ['type' => 'integer'],
                ],
            ]),
            $this->tool('dish_composition', 'Повний склад страви до сирого продукту (з напівфабрикатами) і КБЖВ на 100 г. Перевіряйте кожну страву перед тим, як ставити в меню.', [
                'type' => 'object', 'properties' => ['dish_id' => ['type' => 'integer']], 'required' => ['dish_id'],
            ]),
            $this->tool('find_ingredients', 'Пошук продуктів довідника (id, БЖВ на 100 г) — для заміни продукту чи нової техкарти.', [
                'type' => 'object', 'properties' => ['q' => ['type' => 'string']], 'required' => ['q'],
            ]),
            $this->tool('check_menu', 'Перевірити чернетку меню кодом БЕЗ подачі: виключення на всю глибину, дні доставки, биті продукти, КБЖВ дня проти цілі, правила профілю, повтори, навантаження кухні, дедлайн. error треба виправити, warn — пояснити в summary.', $menuSchema),
            $this->tool('submit_menu', 'Подати меню на погодження людині. Після погодження CRM сама запише страви й персональне меню. Перед цим — check_menu без error.', [
                'type' => 'object',
                'properties' => [
                    'title' => ['type' => 'string'],
                    'summary' => ['type' => 'string', 'description' => 'людською мовою: що зроблено, як враховано побажання, пояснення до попереджень'],
                    'payload' => $menuSchema,
                ],
                'required' => ['summary', 'payload'],
            ]),
            $this->tool('submit_replacements', 'Подати заміни в стравах циклу для цього замовлення (діють щоразу, коли страва в циклі). Продукт → продукт: dish_id + original_product_id + replacement_product_id; «не класти»: без replacement_product_id; уся страва → інша: dish_id + replacement_dish_id без original_product_id.', [
                'type' => 'object',
                'properties' => [
                    'summary' => ['type' => 'string'],
                    'payload' => [
                        'type' => 'object',
                        'properties' => [
                            'mode' => ['type' => 'string', 'enum' => ['merge'], 'description' => 'merge — додати до наявних замін'],
                            'items' => ['type' => 'array', 'items' => [
                                'type' => 'object',
                                'additionalProperties' => false,
                                'properties' => [
                                    'dish_id' => ['type' => 'integer', 'description' => 'страва циклу'],
                                    'original_product_id' => ['type' => 'integer', 'description' => 'продукт, який міняємо або прибираємо'],
                                    'replacement_product_id' => ['type' => 'integer', 'description' => 'чим замінити (немає — «не класти»)'],
                                    'replacement_dish_id' => ['type' => 'integer', 'description' => 'заміна всієї страви'],
                                    'comment' => ['type' => 'string'],
                                ],
                                'required' => ['dish_id'],
                            ]],
                        ],
                        'required' => ['items'],
                    ],
                ],
                'required' => ['summary', 'payload'],
            ]),
            $this->tool('progress', 'Коротко написати менеджеру, що зараз робите (видно в треді задачі). Одне речення.', [
                'type' => 'object', 'properties' => ['text' => ['type' => 'string']], 'required' => ['text'],
            ]),
            $this->tool('remember', 'Запамʼятати факт про клієнта чи правило на майбутнє (ключ client:<id>:<тема> або rules:<тема>).', [
                'type' => 'object', 'properties' => ['key' => ['type' => 'string'], 'value' => ['type' => 'string']], 'required' => ['key', 'value'],
            ]),
            $this->tool('ask_manager', 'Спитати менеджера, коли без відповіді не можна (суперечливий бриф, немає цілей, немає днів). Робота стане на паузу до відповіді.', [
                'type' => 'object', 'properties' => ['question' => ['type' => 'string']], 'required' => ['question'],
            ]),
            $this->tool('finish', 'Завершити задачу: що зроблено й що лишилось людині.', [
                'type' => 'object', 'properties' => ['summary' => ['type' => 'string']], 'required' => ['summary'],
            ]),
        ];
    }

    public function call(string $name, array $input): ToolResult
    {
        return match ($name) {
            'task_context'        => ToolResult::ok($this->context()),
            'search_dishes'       => ToolResult::ok(app(DishCatalog::class)->search(['order_id' => $this->task->order_id] + array_intersect_key($input, array_flip(['q', 'ingredient', 'kind', 'limit'])))),
            'dish_composition'    => ToolResult::ok($this->composition((int) ($input['dish_id'] ?? 0))),
            'find_ingredients'    => ToolResult::ok($this->ingredients((string) ($input['q'] ?? ''))),
            'check_menu'          => ToolResult::ok($this->checkMenu($input)),
            'submit_menu'         => $this->submit('individual_week_menu', $input),
            'submit_replacements' => $this->submit('order_replacements', $input),
            'progress'            => $this->progress((string) ($input['text'] ?? '')),
            'remember'            => $this->remember((string) ($input['key'] ?? ''), (string) ($input['value'] ?? '')),
            'ask_manager'         => ToolResult::pause((string) ($input['question'] ?? '')),
            'finish'              => ToolResult::finish((string) ($input['summary'] ?? '')),
            default               => ToolResult::error("Невідомий інструмент «{$name}»"),
        };
    }

    private function tool(string $name, string $description, array $schema): array
    {
        return ['name' => $name, 'description' => $description, 'input_schema' => $schema];
    }

    private function order(): Order
    {
        return Order::with(['client.ingredientExclusions', 'client.dishExclusions', 'client.mealTypes', 'client.replacementBundles.items.originalIngredient', 'ingredientExclusions'])
            ->findOrFail($this->task->order_id);
    }

    private function context(): array
    {
        $order = $this->order();
        $client = $order->client;
        [$from, $to] = $this->period($order);
        $profile = ClientDietProfile::where('client_id', $order->client_id)->first();
        $groupNames = DB::table('ingredient_groups')->pluck('name', 'slug');
        $mealNames = DB::table('meal_types')->pluck('name', 'id');

        $days = DB::table('order_days')->where('order_id', $order->id)
            ->whereBetween('date', [$from, $to])->orderBy('date')->pluck('date')
            ->map(fn ($d) => substr((string) $d, 0, 10))->values();

        $personal = DB::table('order_day_dishes')->where('order_id', $order->id)->whereBetween('date', [$from, $to])
            ->join('dishes', 'dishes.id', '=', 'order_day_dishes.dish_id')
            ->orderBy('date')->get(['order_day_dishes.date', 'order_day_dishes.meal_type_id', 'dishes.id as dish_id', 'dishes.name', 'order_day_dishes.weight_grams'])
            ->groupBy(fn ($r) => substr((string) $r->date, 0, 10));

        $history = $this->task->proposals()->latest('id')->limit(5)->get(['id', 'status', 'review_comment', 'version', 'checks'])
            ->map(fn ($p) => ['id' => $p->id, 'status' => $p->status, 'version' => $p->version, 'review_comment' => $p->review_comment,
                'warnings' => MenuGuard::warnings((array) $p->checks)]);

        $memory = DB::table('ai_agent_memory')->where('ai_agent_id', $this->task->ai_agent_id)
            ->where('key', 'like', "client:{$order->client_id}:%")->pluck('value', 'key');

        return [
            'task' => ['id' => $this->task->id, 'title' => $this->task->title, 'details' => $this->task->details, 'period' => [$from, $to], 'deadline' => $this->task->due_at?->toDateTimeString()],
            'client' => [
                'id' => $client?->id, 'name' => $client?->name,
                'menu_brief' => $client?->menu_brief, 'allergies' => $client?->allergies, 'production_comment' => $client?->production_comment,
                'meal_types' => $client?->mealTypes->map(fn ($m) => ['id' => $m->id, 'name' => $m->name])->values(),
            ],
            'order' => [
                'id' => $order->id, 'brand' => $order->project, 'menu_type' => $order->menu_type,
                'targets' => ['kcal' => $order->calories, 'protein_g' => $order->target_protein_g, 'fat_g' => $order->target_fats_g, 'carbs_g' => $order->target_carbs_g],
            ],
            'delivery_days' => $days,
            'exclusions' => [
                'ingredients' => $order->effectiveExcludedIngredients()->map(fn ($i) => ['id' => $i->id, 'name' => $i->name])->values(),
                'groups' => collect((array) ($profile?->excluded_groups ?? []))->map(fn ($s) => ['slug' => $s, 'name' => $groupNames[$s] ?? $s])->values(),
                'dishes' => $client?->dishExclusions->map(fn ($d) => ['id' => $d->id, 'name' => $d->name])->values() ?? [],
            ],
            'profile' => $profile ? [
                'mode' => $profile->mode, 'mode_label' => ClientDietProfile::MODES[$profile->mode] ?? $profile->mode,
                'avoid' => $profile->avoid, 'want_more' => $profile->want_more, 'rules' => $profile->rules,
                'favorite_dishes' => $profile->favorite_dish_ids ? Dish::whereIn('id', $profile->favorite_dish_ids)->pluck('name', 'id') : [],
                'notes' => $profile->notes,
            ] : null,
            'cycle_by_date' => $days->mapWithKeys(fn ($d) => [$d => $this->cycleFor($order, $d, $mealNames)]),
            'personal_menu_written' => $personal->map(fn ($rows) => $rows->map(fn ($r) => ['meal' => $mealNames[$r->meal_type_id] ?? $r->meal_type_id, 'dish_id' => $r->dish_id, 'name' => $r->name, 'weight_grams' => $r->weight_grams])->values()),
            'previous_proposals' => $history,
            'memory' => $memory,
        ];
    }

    /** @return array{0: string, 1: string} */
    private function period(Order $order): array
    {
        $from = $this->task->period_from?->toDateString() ?? now()->addDay()->toDateString();
        $to = $this->task->period_to?->toDateString() ?? Carbon::parse($from)->addDays(6)->toDateString();

        return [$from, $to];
    }

    /** Страви циклу на дату для прийомів клієнта — щоб спершу адаптувати цикл. */
    private function cycleFor(Order $order, string $date, $mealNames): array
    {
        try {
            $plan = $order->effectiveMenuPlan();
            if (!$plan) return [];
            $menu = DailyMenu::where('menu_plan_id', $plan->id)->where('day_number', $plan->globalDayFor($date))->with('menuItems.dish')->first();
            $mine = $order->client?->mealTypes->pluck('id')->all() ?? [];

            return $menu ? $menu->menuItems
                ->filter(fn ($i) => $i->dish && (!$mine || in_array($i->meal_type_id, $mine, true)))
                ->map(fn ($i) => ['meal' => $mealNames[$i->meal_type_id] ?? $i->meal_type_id, 'meal_type_id' => $i->meal_type_id, 'dish_id' => $i->dish_id, 'name' => $i->dish->name])
                ->values()->all() : [];
        } catch (Throwable $e) {
            report($e);

            return [];
        }
    }

    private function composition(int $dishId): array
    {
        $dish = Dish::find($dishId);
        if (!$dish) return ['error' => "страви #{$dishId} немає"];
        $order = $this->order();
        $excluded = collect($order->effectiveExcludedIngredientIds())->flip();
        $profile = ClientDietProfile::where('client_id', $order->client_id)->first();
        $groups = IngredientGroup::map();
        foreach ((array) ($profile?->excluded_groups ?? []) as $slug) foreach ($groups[$slug] ?? [] as $id) $excluded[$id] = true;

        $walk = function (int $id, int $depth, string $path) use (&$walk, $excluded): array {
            if ($depth > 8) return [];
            $out = [];
            $rows = DB::table('dish_ingredients')->where('dish_id', $id)
                ->leftJoin('ingredients', 'ingredients.id', '=', 'dish_ingredients.ingredient_id')
                ->leftJoin('dishes', 'dishes.id', '=', 'dish_ingredients.child_dish_id')
                ->get(['dish_ingredients.ingredient_id', 'dish_ingredients.child_dish_id', 'dish_ingredients.net_weight_g', 'ingredients.name as ing', 'dishes.name as pf']);
            foreach ($rows as $r) {
                if ($r->ingredient_id) {
                    $out[] = ['ingredient_id' => (int) $r->ingredient_id, 'name' => $r->ing, 'net_g' => (float) $r->net_weight_g, 'via' => $path ?: null, 'excluded_for_client' => isset($excluded[(int) $r->ingredient_id])];
                }
                if ($r->child_dish_id) $out = array_merge($out, $walk((int) $r->child_dish_id, $depth + 1, trim($path . ' › ' . $r->pf, ' ›')));
            }

            return $out;
        };

        $t = $dish->calculated_totals;
        $w = (float) ($t['output_weight'] ?: 1);

        return [
            'id' => $dish->id, 'name' => $dish->name, 'base_weight_g' => round($w),
            'per_100g' => ['kcal' => round($t['kcal'] / $w * 100), 'p' => round($t['prot'] / $w * 100, 1), 'f' => round($t['fat'] / $w * 100, 1), 'c' => round($t['carb'] / $w * 100, 1)],
            'ingredients' => $walk($dish->id, 0, ''),
        ];
    }

    private function ingredients(string $q): array
    {
        return DB::table('ingredients')->where('name', 'like', '%' . $q . '%')->orderBy('name')->limit(30)
            ->get(['id', 'name', 'proteins_100g as p', 'fats_100g as f', 'carbs_100g as c'])
            ->map(fn ($r) => (array) $r)->all();
    }

    private function checkMenu(array $payload): array
    {
        $payload['order_id'] = $this->task->order_id;
        $errors = app(IndividualWeekMenu::class)->validate($payload);
        if ($errors) return ['format_errors' => $errors];
        $checks = app(MenuGuard::class)->check($payload);

        return [
            'errors' => MenuGuard::errors($checks),
            'warnings' => MenuGuard::warnings($checks),
            'days' => collect(app(IndividualWeekMenu::class)->dayTotals($payload))
                ->map(fn ($r) => ['date' => $r['date'], 'kcal' => $r['kcal'], 'p' => $r['p'], 'f' => $r['f'], 'c' => $r['c']])->all(),
        ];
    }

    private function submit(string $type, array $input): ToolResult
    {
        $payload = (array) ($input['payload'] ?? []);
        $payload['order_id'] = $this->task->order_id;
        $res = app(ProposalDesk::class)->submit($this->task->agent, [
            'type' => $type, 'title' => $input['title'] ?? null, 'summary' => $input['summary'] ?? null,
            'task_id' => $this->task->id, 'payload' => $payload,
        ]);
        if ($res['errors']) return ToolResult::error(['errors' => $res['errors']]);

        $p = $res['proposal'];
        $this->task->log('step', ($p->status === 'shadow' ? 'Тіньова пропозиція (не виконується)' : 'Подано на погодження') . ": «{$p->title}» (#{$p->id})", [], null, $p->id);

        return ToolResult::ok(['proposal_id' => $p->id, 'status' => 'pending', 'warnings' => $res['warnings'] ?? []]);
    }

    private function progress(string $text): ToolResult
    {
        $this->task->log('step', mb_substr($text, 0, 500));

        return ToolResult::ok(['ok' => true]);
    }

    private function remember(string $key, string $value): ToolResult
    {
        if (!preg_match('/^(client:\d+:|rules:)[\w:.\-]+$/u', $key)) return ToolResult::error('ключ має бути client:<id>:<тема> або rules:<тема>');
        $this->task->agent->memory()->updateOrCreate(['key' => $key], ['value' => $value]);

        return ToolResult::ok(['ok' => true]);
    }
}
