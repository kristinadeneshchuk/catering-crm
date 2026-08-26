<?php

namespace App\Services\Menu;

use App\Models\DailyMenu;
use App\Models\Dish;
use App\Models\Ingredient;
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

    /** Нижче цієї ваги інгредієнт нічого не вирішує: сіль, олія, спеції. */
    private const MIN_INGREDIENT_G = 20;

    /** Скільки основних інгредієнтів показувати на страву. */
    private const MAX_INGREDIENTS_SHOWN = 6;

    /** Глибина розкриття вкладених напівфабрикатів. */
    private const MAX_NESTING = 3;

    /**
     * Інгредієнти, які нічого не кажуть про страву.
     *
     * Вода за вагою часто перша (у 60 страв із 211) і витісняла зі списку
     * справжній продукт. Клієнт у брифі про воду не пише.
     */
    private const NOISE_INGREDIENTS = ['вода', 'сіль', 'цукор', 'лід'];

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

        $picked = $this->ask($brief, $order, $mealTypes, $dishes, $this->kitchenIngredientIds($date));

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
        // Назвами, а не id: модель міркує про «сніданок», а не про «8».
        $mealNames = MealType::pluck('name', 'id');

        $usage = DB::table('daily_menu_dishes')
            ->select('dish_id', 'meal_type_id')
            ->distinct()
            ->get()
            ->groupBy('dish_id')
            ->map(fn ($rows) => $rows->pluck('meal_type_id')
                ->map(fn ($id) => $mealNames[$id] ?? null)
                ->filter()->unique()->values()->all());

        return Dish::where('is_semi_finished', false)
            ->with(['dishIngredients.ingredient', 'dishIngredients.childDish.dishIngredients.childDish.dishIngredients'])
            ->orderBy('name')
            ->get()
            ->map(fn (Dish $d) => [
                'id'          => $d->id,
                'name'        => $d->name,
                'meal_types'  => $usage[$d->id] ?? [],
                'ingredients' => $this->mainIngredients($d),
            ]);
    }

    /**
     * Основна сировина страви: id => назва.
     *
     * Модель має бачити, з ЧОГО страва, а не лише її назву — клієнт у брифі
     * пише саме про продукти («люблю курку, не їм буряк»), і за назвою це не
     * вгадується.
     *
     * Розкриваємо вкладені напівфабрикати. Без цього «Яловичина карі з паровим
     * рисом» показувала склад «черрі»: рис і яловичина лежать усередині
     * вкладених страв, а не прямими інгредієнтами. Це не дрібниця — вкладені
     * компоненти найбільші за вагою.
     *
     * Дрібницю до 20 г (сіль, олія, спеції) не показуємо: вона нічого не
     * вирішує, а промпт роздуває.
     *
     * @return array<int, string>
     */
    private function mainIngredients(Dish $dish): array
    {
        $weights = $this->collectIngredientWeights($dish);

        arsort($weights);

        $names = Ingredient::whereIn('id', array_keys($weights))->pluck('name', 'id');

        $out = [];

        foreach ($weights as $id => $grams) {
            if ($grams < self::MIN_INGREDIENT_G || ! isset($names[$id])) {
                continue;
            }

            if (in_array(mb_strtolower(trim($names[$id])), self::NOISE_INGREDIENTS, true)) {
                continue;
            }

            $out[$id] = $names[$id];

            if (count($out) >= self::MAX_INGREDIENTS_SHOWN) {
                break;
            }
        }

        return $out;
    }

    /**
     * Сумарна вага кожного інгредієнта страви з урахуванням вкладених страв.
     *
     * Вкладена страва входить не цілком, а шматком: 400 г від напівфабрикату,
     * у якого своя базова вага. Тому її склад масштабуємо — інакше поріг
     * значущості спрацює навмання.
     *
     * @param  array<int, bool>  $seen  захист від зациклених вкладень
     * @return array<int, float>  ingredient_id => грами
     */
    private function collectIngredientWeights(Dish $dish, float $scale = 1.0, array $seen = []): array
    {
        if ($scale <= 0 || isset($seen[$dish->id]) || count($seen) > self::MAX_NESTING) {
            return [];
        }

        $seen[$dish->id] = true;
        $weights = [];

        foreach ($dish->dishIngredients as $di) {
            $grams = (float) $di->net_weight_g * $scale;

            if ($di->ingredient_id) {
                $weights[$di->ingredient_id] = ($weights[$di->ingredient_id] ?? 0) + $grams;

                continue;
            }

            $child = $di->childDish;

            if (! $child) {
                continue;
            }

            $base = (float) ($child->base_weight_g ?? 0);

            foreach ($this->collectIngredientWeights($child, $base > 0 ? $grams / $base : 0, $seen) as $id => $g) {
                $weights[$id] = ($weights[$id] ?? 0) + $g;
            }
        }

        return $weights;
    }

    /**
     * Інгредієнти, які кухня вже ріже цього дня для стандартного меню.
     *
     * Рахуємо саме інгредієнти, а не страви: користь не в тому, щоб дати
     * клієнту ту саму страву, а в тому, щоб не запускати окремий процес заради
     * двохсот грамів. Якщо курка вже в роботі — будь-яка страва з курки
     * дешева, навіть якщо самої страви сьогодні в меню немає.
     *
     * Поріг той самий, що на сторінці «Персональні меню»: від 20 г.
     *
     * @return array<int, int>
     */
    private function kitchenIngredientIds(string $date): array
    {
        $plan = MenuPlan::default();

        if (! $plan) {
            return [];
        }

        $menu = DailyMenu::where('menu_plan_id', $plan->id)
            ->where('day_number', $plan->globalDayFor($date))
            ->with('menuItems.dish.dishIngredients')
            ->first();

        if (! $menu) {
            return [];
        }

        $ids = [];

        foreach ($menu->menuItems as $item) {
            foreach ($item->dish?->dishIngredients ?? [] as $di) {
                if ($di->ingredient_id && (float) $di->net_weight_g >= self::MIN_INGREDIENT_G) {
                    $ids[] = $di->ingredient_id;
                }
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * Запит до моделі. Повертає масив meal_type_id => dish_id.
     *
     * @return array<int, int>
     */
    private function ask(string $brief, Order $order, $mealTypes, $dishes, array $kitchenIngredientIds): array
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
                // temperature навмисно не передаємо: моделі від gpt-5.5 приймають
                // лише значення за замовчуванням і відмовляють на будь-якому
                // іншому. Без параметра працюють і старі, і нові.
                'messages'        => [
                    ['role' => 'system', 'content' => $this->systemPrompt()],
                    ['role' => 'user',   'content' => $this->userPrompt($brief, $order, $mealTypes, $dishes, $kitchenIngredientIds)],
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

        У кожної страви показано її основні інгредієнти. Зірочка (*) означає,
        що цей інгредієнт кухня вже готує сьогодні для стандартного меню.

        Порядок пріоритетів — саме такий:

        1. АНКЕТА. Це головне. Категорично не пропонуй продуктів і страв, які
           клієнт не їсть або не любить — дивись на інгредієнти, а не лише на
           назву страви. І навпаки: активно бери те, що він назвав улюбленим.
           Якщо людина пише «люблю курку і рибу» — саме вони мають бути в меню.
        2. ВІДПОВІДНІСТЬ ПРИЙОМУ. Страва має пасувати: у списку вказано, на які
           прийоми вона зазвичай іде. Не став вечерю на сніданок.
        3. РІЗНОМАНІТНІСТЬ. Не повторюй ту саму основну сировину в усіх
           прийомах дня.
        4. ЕКОНОМІКА КУХНІ. Коли кілька страв однаково підходять за пунктами
           1–3, обирай ту, де більше інгредієнтів із зірочкою: їх уже ріжуть,
           і окремий процес заради двохсот грамів запускати не доведеться.

        Пункт 4 НІКОЛИ не переважає пункт 1. Краще окрема страва з улюблених
        продуктів, ніж зручна кухні страва з тих, які клієнт не їсть.

        Технічні обмеження:
        - Обирай страви ТІЛЬКИ зі списку доступних, за їхніми id. Не вигадуй
          назв і не пропонуй того, чого немає в списку.
        - Рівно одна страва на кожен запитаний прийом їжі.
        - Не рахуй ваги, калорії й БЖУ. Це зробить система.

        Відповідай СУВОРО у форматі JSON:
        {"meals":[{"meal_type_id":1,"dish_id":123,"reason":"коротко чому"}]}
        TXT;
    }

    private function userPrompt(string $brief, Order $order, $mealTypes, $dishes, array $kitchenIngredientIds): string
    {
        $mealsList = $mealTypes
            ->map(fn (MealType $m) => "- id={$m->id}: {$m->name}")
            ->implode("\n");

        $kitchen = array_flip($kitchenIngredientIds);

        $dishList = $dishes
            ->map(function (array $d) use ($kitchen) {
                $meals = $d['meal_types'] ? ' | прийоми: '.implode(',', $d['meal_types']) : '';

                // Зірочка = інгредієнт уже сьогодні в роботі на кухні.
                $ingredients = collect($d['ingredients'])
                    ->map(fn (string $name, int $id) => $name.(isset($kitchen[$id]) ? '*' : ''))
                    ->implode(', ');

                $ingredients = $ingredients !== '' ? ' | склад: '.$ingredients : '';

                return "id={$d['id']} | {$d['name']}{$meals}{$ingredients}";
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
