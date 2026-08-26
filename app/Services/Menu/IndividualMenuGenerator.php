<?php

namespace App\Services\Menu;

use App\Models\DailyMenu;
use App\Models\Dish;
use App\Models\MealPlan;
use App\Models\MealType;
use App\Models\MenuPlan;
use App\Models\Order;
use App\Models\OrderDayDish;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

/**
 * Підбір персонального меню для індивідуального клієнта.
 *
 * ШІ отримує бриф клієнта і наш список страв, а повертає ЛИШЕ id страв на
 * кожен прийом. Він нічого не вигадує і нічого не рахує:
 *
 *  - страву, якої немає в базі, приготувати неможливо, тож приймаємо тільки
 *    існуючі id — усе інше відкидаємо;
 *  - грамівки рахує CRM (buildPlanFromPersonal) під калораж замовлення, а не
 *    модель: інакше вага поїде і виробничий лист брехатиме.
 *
 * Виключення клієнта тут навмисно не застосовуємо. Бриф і так описує, чого
 * людина не їсть, а персональне меню довідники виключень не фільтрують —
 * менеджер бачить результат і може виправити руками.
 */
class IndividualMenuGenerator
{
    private const API_URL = 'https://api.openai.com/v1/chat/completions';

    /**
     * @return array{assigned: int, meals: array<int, string>, skipped: array<int, string>}
     */
    public function generate(Order $order, string $date): array
    {
        $client = $order->client;

        if (! $client) {
            throw ValidationException::withMessages([
                'client' => "Замовлення #{$order->id} не привязане до клієнта.",
            ]);
        }

        $brief = trim((string) $client->menu_brief);

        if ($brief === '') {
            throw ValidationException::withMessages([
                'brief' => "У клієнта «{$client->name}» не заповнений бриф. Картка клієнта → «Бриф для індивідуального меню».",
            ]);
        }

        $mealTypes = $this->mealTypesFor($order);

        if ($mealTypes->isEmpty()) {
            throw ValidationException::withMessages([
                'meals' => "Для {$order->calories} ккал не визначено жодного прийому їжі.",
            ]);
        }

        $dishes = $this->candidateDishes();

        if ($dishes->isEmpty()) {
            throw ValidationException::withMessages([
                'dishes' => 'У базі немає жодної страви, з якої можна скласти меню.',
            ]);
        }

        $picked = $this->ask($brief, $order, $mealTypes, $dishes, $this->kitchenDishIds($date));

        return $this->store($order, $date, $mealTypes, $dishes, $picked);
    }

    /**
     * Прийоми, які цьому замовленню треба закрити: набір клієнта ∩ стандарт
     * для калоражу — рівно те, що потім рахує виробництво.
     */
    private function mealTypesFor(Order $order)
    {
        $own = $order->client?->mealTypes?->pluck('sort_order')->all() ?? [];
        $std = MealPlan::getAllowedSortOrders((int) $order->calories);

        $allowed = $own ? array_intersect($std, $own) : $std;

        return MealType::whereIn('sort_order', $allowed)->orderBy('sort_order')->get();
    }

    /**
     * Страви, з яких можна складати меню.
     *
     * Напівфабрикати не беремо — це заготовки, а не готові страви. Для кожної
     * додаємо, на які прийоми вона зазвичай іде: без цього модель ставить
     * борщ на сніданок.
     */
    private function candidateDishes()
    {
        $usage = DB::table('daily_menu_dishes')
            ->select('dish_id', 'meal_type_id')
            ->distinct()
            ->get()
            ->groupBy('dish_id')
            ->map(fn ($rows) => $rows->pluck('meal_type_id')->all());

        return Dish::where('is_semi_finished', false)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Dish $d) => [
                'id'         => $d->id,
                'name'       => $d->name,
                'meal_types' => $usage[$d->id] ?? [],
            ]);
    }

    /**
     * Страви стандартного меню на цю дату.
     *
     * Головна причина, чому вони тут: кухня вже їх готує. Взяти для
     * індивідуального клієнта те, що й так у роботі, дешевше, ніж запускати
     * окремий процес заради двохсот грамів.
     *
     * @return array<int, int>
     */
    private function kitchenDishIds(string $date): array
    {
        $plan = MenuPlan::default();

        if (! $plan) {
            return [];
        }

        $menu = DailyMenu::where('menu_plan_id', $plan->id)
            ->where('day_number', $plan->globalDayFor($date))
            ->first();

        if (! $menu) {
            return [];
        }

        return DB::table('daily_menu_dishes')
            ->where('daily_menu_id', $menu->id)
            ->pluck('dish_id')
            ->all();
    }

    /**
     * Запит до моделі. Повертає масив meal_type_id => dish_id.
     *
     * @return array<int, int>
     */
    private function ask(string $brief, Order $order, $mealTypes, $dishes, array $kitchenDishIds): array
    {
        $key = (string) config('services.openai.key');

        if ($key === '') {
            throw ValidationException::withMessages([
                'openai' => 'Не заданий OPENAI_API_KEY — підбір меню недоступний.',
            ]);
        }

        $response = Http::withToken($key)
            ->timeout(120)
            ->asJson()
            ->post(self::API_URL, [
                'model'           => config('services.openai.menu_model'),
                'response_format' => ['type' => 'json_object'],
                'temperature'     => 0.4,
                'messages'        => [
                    ['role' => 'system', 'content' => $this->systemPrompt()],
                    ['role' => 'user',   'content' => $this->userPrompt($brief, $order, $mealTypes, $dishes, $kitchenDishIds)],
                ],
            ]);

        if ($response->failed()) {
            throw ValidationException::withMessages([
                'openai' => 'OpenAI відповів помилкою: '.mb_substr($response->body(), 0, 300),
            ]);
        }

        $content = $response->json('choices.0.message.content');
        $parsed  = json_decode((string) $content, true);

        if (! is_array($parsed) || ! isset($parsed['meals']) || ! is_array($parsed['meals'])) {
            throw ValidationException::withMessages([
                'openai' => 'Модель повернула відповідь у неочікуваному форматі.',
            ]);
        }

        $picked = [];

        foreach ($parsed['meals'] as $row) {
            $mealTypeId = (int) ($row['meal_type_id'] ?? 0);
            $dishId     = (int) ($row['dish_id'] ?? 0);

            if ($mealTypeId && $dishId) {
                $picked[$mealTypeId] = $dishId;
            }
        }

        return $picked;
    }

    private function systemPrompt(): string
    {
        return <<<'TXT'
        Ти — технолог служби доставки здорового харчування. Складаєш персональне
        денне меню для клієнта з його анкети.

        Правила, які не можна порушувати:
        1. Обирай страви ТІЛЬКИ зі списку доступних, за їхніми id. Не вигадуй
           назв і не пропонуй того, чого немає в списку.
        2. Рівно одна страва на кожен запитаний прийом їжі.
        3. Страва має пасувати прийому: у списку вказано, на які прийоми вона
           зазвичай іде. Не став вечерю на сніданок.
        4. Поважай анкету: не пропонуй того, що клієнт не їсть або не любить,
           і по можливості бери те, що він любить.
        5. За інших рівних обирай страви з позначкою «вже готується сьогодні» —
           кухня їх і так робить.
        6. Не рахуй ваги, калорії й БЖУ. Це зробить система.

        Відповідай СУВОРО у форматі JSON:
        {"meals":[{"meal_type_id":1,"dish_id":123,"reason":"коротко чому"}]}
        TXT;
    }

    private function userPrompt(string $brief, Order $order, $mealTypes, $dishes, array $kitchenDishIds): string
    {
        $mealsList = $mealTypes
            ->map(fn (MealType $m) => "- id={$m->id}: {$m->name}")
            ->implode("\n");

        $kitchen = array_flip($kitchenDishIds);

        $dishList = $dishes
            ->map(function (array $d) use ($kitchen) {
                $meals = $d['meal_types'] ? ' | прийоми: '.implode(',', $d['meal_types']) : '';
                $today = isset($kitchen[$d['id']]) ? ' | ВЖЕ ГОТУЄТЬСЯ СЬОГОДНІ' : '';

                return "id={$d['id']} | {$d['name']}{$meals}{$today}";
            })
            ->implode("\n");

        return <<<TXT
        АНКЕТА КЛІЄНТА:
        {$brief}

        ЦІЛЬОВА КАЛОРІЙНІСТЬ НА ДЕНЬ: {$order->calories} ккал
        (ваги страв підбере система — тобі треба лише обрати страви)

        ПРИЙОМИ ЇЖІ, ЯКІ ТРЕБА ЗАКРИТИ:
        {$mealsList}

        ДОСТУПНІ СТРАВИ:
        {$dishList}
        TXT;
    }

    /**
     * Записуємо підбір. Все, що модель вигадала, відкидаємо і повертаємо
     * менеджеру списком — щоб було видно, які прийоми лишились порожніми.
     *
     * @param  array<int, int>  $picked
     * @return array{assigned: int, meals: array<int, string>, skipped: array<int, string>}
     */
    private function store(Order $order, string $date, $mealTypes, $dishes, array $picked): array
    {
        $byId    = $dishes->keyBy('id');
        $meals   = [];
        $skipped = [];

        DB::transaction(function () use ($order, $date, $mealTypes, $picked, $byId, &$meals, &$skipped) {
            foreach ($mealTypes as $mealType) {
                $dishId = $picked[$mealType->id] ?? null;

                if (! $dishId || ! $byId->has($dishId)) {
                    $skipped[] = $mealType->name;

                    continue;
                }

                // Спершу прибираємо попередній підбір на цей прийом, потім
                // пишемо новий. Явно, а не updateOrCreate: там дата в WHERE
                // йде сирим рядком, а в БД лягає через каст — на різних
                // драйверах це поводиться по-різному і плодить дублі.
                OrderDayDish::where('order_id', $order->id)
                    ->whereDate('date', $date)
                    ->where('meal_type_id', $mealType->id)
                    ->delete();

                OrderDayDish::create([
                    'order_id'     => $order->id,
                    'date'         => $date,
                    'meal_type_id' => $mealType->id,
                    'dish_id'      => $dishId,
                    // weight_grams лишаємо порожнім — його рахує CRM під калораж.
                    'weight_grams' => null,
                ]);

                $meals[] = $mealType->name.': '.$byId[$dishId]['name'];
            }
        });

        return ['assigned' => count($meals), 'meals' => $meals, 'skipped' => $skipped];
    }
}
