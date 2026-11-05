<?php

namespace Database\Seeders;

use App\Models\Account;
use App\Models\Client;
use App\Models\ClientAddress;
use App\Models\CourierMileageLog;
use App\Models\DishRating;
use App\Models\Employee;
use App\Models\EmployeeBonus;
use App\Models\EmployeePenalty;
use App\Models\EmployeeShift;
use App\Models\Ingredient;
use App\Models\KitchenNotification;
use App\Models\Order;
use App\Models\OrderCall;
use App\Models\OrderDay;
use App\Models\OrderDayDish;
use App\Models\StockDocument;
use App\Models\StockDocumentItem;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Agents\OrderReplacements;
use App\Services\Kitchen\KitchenStockDebit;
use App\Services\RouteLogixService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Демо NutriX у масштабі ~280 раціонів на будній день.
 *
 * Пропорції — з прода (docs/demo-proportions.md), обсяг ×4. Усі люди, телефони
 * й адреси вигадані. Дати рахуються від сьогодні: історія ~90 днів, наперед ~16.
 *
 * Пишемо через моделі й сервіси CRM, а не прямими апдейтами: ціни й статуси
 * рахують хуки Order/OrderDay, заміни — OrderReplacements + ReplacementInheritance,
 * маршрути — RouteLogixService (план «прилітає» з підробленого HTTP),
 * списання складу — KitchenStockDebit за нормою.
 *
 * Запуск — scripts/nutrix-demo-280.sh (див. docs/demo.md).
 */
class NutrixDemo280Seeder extends Seeder
{
    /** Раціонів у будній день, на які калібруємо кількість «потоків» клієнтів. */
    private const TARGET_WEEKDAY = 280;

    private const HISTORY_DAYS = 90;
    private const FUTURE_DAYS = 16;
    /** Вікно, на якому перевіряємо ~280: минулі 30 днів і 14 наперед. */
    private const WINDOW_PAST = 30;
    private const WINDOW_FUTURE = 14;
    /** Клієнти, що замовляли давно й пішли (для бази ~4× від прода). */
    private const DORMANT_CLIENTS = 1200;
    /** Минулі дні, за які є маршрути, табель, склад. */
    private const OPS_DAYS = 30;

    private array $female = ['Олена', 'Анна', 'Марія', 'Ірина', 'Катерина', 'Юлія', 'Наталія', 'Тетяна', 'Оксана', 'Вікторія', 'Дарина', 'Софія', 'Аліна', 'Христина', 'Яна', 'Леся', 'Світлана', 'Олександра', 'Діана', 'Мирослава', 'Ольга', 'Людмила', 'Інна', 'Вероніка', 'Анастасія', 'Валерія', 'Поліна', 'Зоряна', 'Карина', 'Єва'];
    private array $male = ['Андрій', 'Олександр', 'Дмитро', 'Максим', 'Сергій', 'Іван', 'Богдан', 'Тарас', 'Владислав', 'Юрій', 'Олег', 'Роман', 'Віталій', 'Артем', 'Назар', 'Денис', 'Євген', 'Ігор', 'Микола', 'Костянтин', 'Павло', 'Ярослав', 'Остап', 'Антон', 'Вадим', 'Марко', 'Кирило', 'Степан', 'Ілля', 'Григорій'];
    /** [чоловіче, жіноче] */
    private array $surnames = [
        ['Коваленко', 'Коваленко'], ['Бондаренко', 'Бондаренко'], ['Шевчук', 'Шевчук'], ['Мельник', 'Мельник'], ['Кравчук', 'Кравчук'],
        ['Ткаченко', 'Ткаченко'], ['Савчук', 'Савчук'], ['Олійник', 'Олійник'], ['Лисенко', 'Лисенко'], ['Гнатюк', 'Гнатюк'],
        ['Петренко', 'Петренко'], ['Марченко', 'Марченко'], ['Руденко', 'Руденко'], ['Поліщук', 'Поліщук'], ['Мороз', 'Мороз'],
        ['Литвиненко', 'Литвиненко'], ['Кузьменко', 'Кузьменко'], ['Захарченко', 'Захарченко'], ['Ярошенко', 'Ярошенко'], ['Симоненко', 'Симоненко'],
        ['Білецький', 'Білецька'], ['Ковальський', 'Ковальська'], ['Вишневський', 'Вишневська'], ['Заєць', 'Заєць'], ['Гончаренко', 'Гончаренко'],
        ['Дячук', 'Дячук'], ['Остапчук', 'Остапчук'], ['Пономаренко', 'Пономаренко'], ['Левченко', 'Левченко'], ['Тимошенко', 'Тимошенко'],
        ['Кравець', 'Кравець'], ['Нестеренко', 'Нестеренко'], ['Фесенко', 'Фесенко'], ['Чорновол', 'Чорновол'], ['Яковенко', 'Яковенко'],
        ['Головко', 'Головко'], ['Сидоренко', 'Сидоренко'], ['Дорошенко', 'Дорошенко'], ['Колесник', 'Колесник'], ['Приходько', 'Приходько'],
        ['Зінченко', 'Зінченко'], ['Гуменюк', 'Гуменюк'], ['Онищенко', 'Онищенко'], ['Стасюк', 'Стасюк'], ['Рибак', 'Рибак'],
        ['Соловйов', 'Соловйова'], ['Карпенко', 'Карпенко'], ['Демченко', 'Демченко'], ['Юрченко', 'Юрченко'], ['Андрієнко', 'Андрієнко'],
    ];

    /** Райони → вулиці. Вага ≈ щільність доставок; порядок = кільце для нарізки маршрутів. */
    private array $districts = [
        'Печерський'      => [14, ['вул. Велика Васильківська', 'вул. Саксаганського', 'бульв. Лесі Українки', 'вул. Генерала Алмазова', 'вул. Михайла Бойчука', 'вул. Іоанна Павла II']],
        'Голосіївський'   => [12, ['вул. Антоновича', 'просп. Голосіївський', 'вул. Деміївська', 'вул. Ломоносова', 'вул. Васильківська', 'вул. Жилянська']],
        'Солом\'янський'  => [9, ['просп. Повітряних Сил', 'вул. Механізаторів', 'вул. Святослава Хороброго', 'бульв. Вацлава Гавела', 'вул. Донецька']],
        'Святошинський'   => [8, ['просп. Берестейський', 'вул. Симиренка', 'вул. Якуба Коласа', 'бульв. Кольцова', 'вул. Тулузи']],
        'Шевченківський'  => [13, ['вул. Ярославів Вал', 'вул. Січових Стрільців', 'вул. Олеся Гончара', 'вул. Дмитрівська', 'вул. Тарасівська', 'вул. Юрія Іллєнка']],
        'Подільський'     => [7, ['вул. Кирилівська', 'вул. Костянтинівська', 'просп. Правди', 'вул. Межигірська']],
        'Оболонський'     => [9, ['просп. Володимира Івасюка', 'вул. Оболонська набережна', 'просп. Оболонський', 'вул. Героїв Полку Азов', 'вул. Тимошенка']],
        'Деснянський'     => [5, ['вул. Закревського', 'просп. Лісовий', 'вул. Драйзера', 'просп. Червоної Калини']],
        'Дніпровський'    => [6, ['бульв. Лесі Українки', 'вул. Ентузіастів', 'вул. Райдужна', 'бульв. Перова']],
        'Дарницький'      => [9, ['просп. Миколи Бажана', 'вул. Срібнокільська', 'вул. Ревуцького', 'вул. Драгоманова', 'вул. Анни Ахматової']],
        'Ірпінь/Буча'     => [5, ['м. Ірпінь, вул. Університетська', 'м. Ірпінь, вул. Мечникова', 'м. Буча, вул. Вокзальна', 'м. Буча, бульв. Богдана Хмельницького']],
        'Крюківщина/Вишневе' => [3, ['м. Вишневе, вул. Святошинська', 'с. Крюківщина, вул. Одеська', 'м. Вишневе, вул. Київська']],
    ];

    private array $sources = ['Написав в телеграм' => 39, 'Залишив заявку СЕО' => 21, 'Залишив заявку РК' => 15, 'Повідомлення в соц мережі з реклами' => 15, 'Написав в вайбер' => 2, 'Телефонний дзвінок' => 2, 'Рекомендація' => 4];
    private array $projects = ['u_fit' => 53, 'avocado_food' => 42, 'afood' => 5];
    private array $kcal = [1800 => 160, 1600 => 132, 2000 => 84, 1300 => 84, 2500 => 81, 3000 => 80, 1200 => 70, 1400 => 50, 2400 => 47, 1500 => 34, 2300 => 33, 2200 => 28, 2100 => 24, 3100 => 20, 1700 => 15, 1000 => 15, 1900 => 12, 1450 => 11, 900 => 11, 1100 => 8];
    private array $firstDur = [3 => 45, 5 => 15, 7 => 12, 1 => 9, 2 => 6, 4 => 6, 14 => 4, 21 => 3];
    private array $renewDur = [5 => 30, 7 => 16, 3 => 10, 4 => 10, 6 => 6, 2 => 5, 1 => 4, 10 => 5, 14 => 6, 20 => 3, 21 => 3, 30 => 2];
    private array $morningTimes = ['06:00-10:00' => 45, '08:30-10:00' => 9, '09:00-10:00' => 8, '08:00-09:30' => 7, '07:00-08:00' => 7, '08:00-10:00' => 7, '06:00-07:30' => 6, '06:00-08:00' => 5, '07:00-09:00' => 4, '06:00-07:00' => 2];
    private array $eveningTimes = ['18:00-22:00' => 75, '20:30-22:00' => 7, '18:00-19:30' => 7, '20:00-22:00' => 6, '19:00-22:00' => 5];

    /** Тариф за брендом і типом замовлення (активні тарифи довідника). */
    private array $tariffMap = [
        'u_fit'        => ['trial' => 14, 'd1' => 15, 'd5' => 16, 'd14' => 17, 'd21' => 18, 'ind' => 62],
        'avocado_food' => ['trial' => 19, 'd1' => 20, 'd5' => 21, 'd14' => 22, 'd21' => 23, 'ind' => 63],
        'afood'        => ['trial' => 27, 'd1' => 28, 'd5' => 29, 'd14' => 30, 'd21' => 31, 'ind' => 61],
    ];

    /** Групи «не їм» → шаблони назв продуктів. */
    private array $exclusionGroups = [
        'без свинини'          => ['Свинин', 'Бекон', 'Ковбас'],
        'без лактози'          => ['Молоко', 'Вершк', 'Сир ', 'Сир,', 'Йогурт', 'Сметан', 'Масло вершк'],
        'без горіхів'          => ['Горіх', 'Мигдал', 'Кеш', 'Фундук', 'Арахіс'],
        'без грибів'           => ['Гриб', 'Печериц', 'Шампін'],
        'не їсть рибу'         => ['Лосос', 'Хек', 'Дорадо', 'Тунець', 'Скумбр', 'Минтай', 'Форел'],
        'без морепродуктів'    => ['Кревет', 'Кальмар', 'Мідії', 'Морепродукт'],
        'без цибулі й часнику' => ['Цибул', 'Часник'],
        'без меду'             => ['Мед'],
        'без глютену'          => ['Борошн', 'Макарон', 'Булгур', 'Кускус', 'Лаваш'],
        'без бобових'          => ['Квасол', 'Нут', 'Сочевиц', 'Горох'],
        'без яєць'             => ['Яйц', 'Яйце'],
        'без томатів'          => ['Томат', 'Помідор', 'Черрі'],
    ];
    private array $prodComments = ['Соус окремо', 'Менше солі', 'Без гострого', 'Гарнір не підсмажувати', 'Хліб не класти', 'Салат без заправки', 'Більше овочів замість гарніру', 'Каші на воді'];
    private array $briefs = ['Тренується 4 рази на тиждень, хоче більше білка', 'Худне до весни, мінімум солодкого на вечерю', 'Вагітність, без сирої риби й непастеризованого', 'Після операції: їжа м\'яка, без смаженого', 'Інсулінорезистентність — низький ГІ, без цукру', 'Не любить каші, на сніданок — яйця/сирники'];

    private Carbon $today;
    private array $accounts = [];
    private array $couriers = [];
    private array $staffByPosition = [];
    private int $phoneSeq = 0;
    /** @var array<int, array<int, int>> meal_type_id => dish ids з циклу */
    private array $dishesByMeal = [];
    /** ingredient_id => [dish ids циклу, де він є] */
    private array $dishesByIngredient = [];
    private array $exclusionPool = [];
    private array $plan = [];
    private array $logistPlan = [];

    public function run(): void
    {
        mt_srand(20261104);
        $this->today = Carbon::today();

        // Демо нічого не шле назовні; план RouteLogix віддаємо самі.
        Http::preventStrayRequests();
        config([
            'services.logist.labels' => true,
            'services.logist.token'  => 'demo',
            'services.logist.url'    => 'http://routelogix.demo',
        ]);
        Http::fake(['routelogix.demo/*' => fn () => Http::response($this->logistPlan)]);

        $this->loadMenuIndex();
        $this->seedStaff();
        $this->seedAccounts();

        $streams = $this->calibrate();
        $this->say('Клієнтів у плані: ' . count($this->plan) . ' (потоків ' . $streams . ')');

        $this->persistClients();
        $this->say('Замовлень: ' . Order::count() . ', днів: ' . OrderDay::count());

        $this->seedPersonalMenus();
        $this->seedRoutes();
        $this->seedAttendanceAndPayroll();
        $this->seedStock();
        $this->seedCashExpenses();
        $this->seedOwnerWithdrawals();

        Carbon::setTestNow();

        // Статуси — від справжнього «сьогодні» сервісом перерахунку.
        Order::query()->orderBy('id')->each(fn (Order $o) => $o->recomputeStatus());
        $this->seedPauses();
        $this->seedCalls();
        $this->seedRatings();

        KitchenNotification::where('created_at', '<', $this->today->copy()->subDays(2))->update(['read_at' => $this->today]);
        $this->say('Готово.');
    }

    // ------------------------------------------------------------------
    // План: потоки клієнтів, калібровані на ~280 у будній день
    // ------------------------------------------------------------------

    /** Підбирає кількість потоків так, щоб будні у вікні давали TARGET_WEEKDAY. */
    private function calibrate(): int
    {
        $streams = 330;
        for ($i = 0; $i < 4; $i++) {
            $avg = $this->buildPlan($streams);
            if (abs($avg - self::TARGET_WEEKDAY) / self::TARGET_WEEKDAY < 0.02) {
                break;
            }
            $streams = (int) round($streams * self::TARGET_WEEKDAY / max(1, $avg));
        }

        return $streams;
    }

    /** Будує план у масивах і повертає середнє раціонів на будній день у вікні. */
    private function buildPlan(int $streams): float
    {
        mt_srand(20261104 + $streams);
        $this->plan = [];
        $start = $this->today->copy()->subDays(self::HISTORY_DAYS);
        $end = $this->today->copy()->addDays(self::FUTURE_DAYS);

        for ($s = 0; $s < $streams; $s++) {
            // Потік уже «йде» на початок історії — без провалу в перші тижні.
            $t = $start->copy()->subDays(mt_rand(0, 30));
            while ($t->lte($end)) {
                $client = $this->planClient($t, $end);
                $this->plan[] = $client;
                $last = Carbon::parse(end($client['orders'])['dates'][count(end($client['orders'])['dates']) - 1]);
                $t = $last->copy()->addDays(1 + $this->pick([0 => 50, 1 => 25, 2 => 10, 4 => 10, 7 => 5]));
            }
        }

        for ($i = 0; $i < self::DORMANT_CLIENTS; $i++) {
            $arrive = $this->today->copy()->subDays(mt_rand(self::HISTORY_DAYS + 10, 300));
            $client = $this->planClient($arrive, $arrive->copy()->addDays(40), maxOrders: 3);
            $this->plan[] = $client;
        }

        // Лічильник раціонів на день (з дочірніми).
        $perDay = [];
        foreach ($this->plan as $c) {
            foreach ($c['orders'] as $o) {
                foreach ($o['dates'] as $d) {
                    $perDay[$d] = ($perDay[$d] ?? 0) + ($o['child'] ? 2 : 1);
                }
            }
        }
        $sum = 0;
        $n = 0;
        for ($d = $this->today->copy()->subDays(self::WINDOW_PAST); $d->lte($this->today->copy()->addDays(self::WINDOW_FUTURE)); $d->addDay()) {
            if ($d->isWeekday()) {
                $sum += $perDay[$d->toDateString()] ?? 0;
                $n++;
            }
        }

        return $n ? $sum / $n : 0;
    }

    private function planClient(Carbon $arrive, Carbon $end, int $maxOrders = 40): array
    {
        $individual = mt_rand(1, 100) <= 21;
        $client = [
            'project'    => $this->pick($this->projects),
            'kcal'       => $this->pick($this->kcal),
            'evening'    => mt_rand(1, 1000) <= 504,
            'individual' => $individual,
            // Ритм: пн–пт / пн–сб / щодня (частки субот і неділь як у проді).
            'pattern'    => $this->pick(['wd' => 58, 'w6' => 14, 'w7' => 28]),
            'orders'     => [],
        ];

        $t = $arrive->copy();
        $first = true;
        while (count($client['orders']) < $maxOrders) {
            $duration = $this->pick($first ? $this->firstDur : $this->renewDur);
            if ($individual && ! $first && $duration < 3) {
                $duration = $this->pick([5 => 60, 7 => 40]);
            }
            $dates = $this->deliveryDates($t, $duration, $client['pattern']);
            $client['orders'][] = [
                'dates'    => $dates,
                // Другий раціон для члена сімʼї — ~5% днів.
                'child'    => mt_rand(1, 100) <= 5,
                'discount' => mt_rand(1, 100) <= 6,
            ];
            $last = Carbon::parse(end($dates));
            if ($last->gt($end)) {
                break;
            }
            if (mt_rand(1, 100) > ($first ? 66 : 77)) {
                break;
            }
            $gap = $this->pick([0 => 78, 1 => 8, 2 => 4, 4 => 3, 10 => 4, 20 => 3]);
            $t = $last->copy()->addDays(1 + $gap);
            $first = false;
        }

        return $client;
    }

    /** $count днів доставки від $from за ритмом клієнта. */
    private function deliveryDates(Carbon $from, int $count, string $pattern): array
    {
        $dates = [];
        $d = $from->copy();
        while (count($dates) < $count) {
            $ok = match ($pattern) {
                'wd'    => $d->isWeekday(),
                'w6'    => ! $d->isSunday(),
                default => true,
            };
            if ($ok) {
                $dates[] = $d->toDateString();
            }
            $d->addDay();
        }

        return $dates;
    }

    // ------------------------------------------------------------------
    // Клієнти й замовлення через моделі
    // ------------------------------------------------------------------

    private function persistClients(): void
    {
        $managerIds = User::where('role', 'manager')->pluck('id')->all();
        $total = count($this->plan);

        // Хронологічно: продовження бачать попередні замовлення клієнта.
        usort($this->plan, fn ($a, $b) => strcmp($a['orders'][0]['dates'][0], $b['orders'][0]['dates'][0]));

        foreach ($this->plan as $i => $p) {
            if ($i % 200 === 0) {
                $this->say("  клієнти {$i}/{$total}");
            }
            $firstDate = Carbon::parse($p['orders'][0]['dates'][0]);
            Carbon::setTestNow($firstDate->copy()->subDays(mt_rand(1, 4))->setTime(mt_rand(9, 20), mt_rand(0, 59)));
            $client = $this->makeClient($p);

            $prevEnd = null;
            $prevCreated = null;
            foreach ($p['orders'] as $n => $o) {
                $start = Carbon::parse($o['dates'][0]);
                // Нове — за 1–4 дні до старту; продовження — у останні дні поточного.
                $created = $n === 0
                    ? $start->copy()->subDays(mt_rand(1, 4))
                    : Carbon::parse($prevEnd)->subDays(mt_rand(0, 2));
                $created->setTime(mt_rand(9, 20), mt_rand(0, 59));
                $noon = $this->today->copy()->setTime(12, 0);
                // Наперед оформлені продовження — у минулі дні, а не всі сьогодні.
                if ($created->gt($noon)) {
                    $lo = $prevCreated && $prevCreated->gt($noon->copy()->subDays(21)) ? $prevCreated->copy() : $noon->copy()->subDays(21);
                    $created = $lo->copy()->addSeconds(mt_rand(3600, max(3601, (int) $lo->diffInSeconds($noon))));
                }
                $prevCreated = $created->copy();
                Carbon::setTestNow($created);

                $order = $this->makeOrder($client, $p, $o, $n === 0);
                if (! $order) {
                    continue;
                }

                if ($n === 0 && ! empty($p['replacements'])) {
                    $this->seedReplacements($order, $client);
                }

                if ($o['child']) {
                    $this->makeOrder($client, $p, $o, false, $order);
                }

                $this->pay($order, $client, $start);
                $prevEnd = end($o['dates']);
            }
        }
    }

    private function makeClient(array &$p): Client
    {
        $female = mt_rand(1, 100) <= 66;
        [$m, $f] = $this->surnames[array_rand($this->surnames)];
        $first = $female ? $this->female[array_rand($this->female)] : $this->male[array_rand($this->male)];
        $district = $this->pick(array_map(fn ($d) => $d[0], $this->districts));
        $streets = $this->districts[$district][1];
        $address = $streets[array_rand($streets)] . ', ' . mt_rand(1, 160);
        $p['district'] = $district;

        $groups = [];
        if (mt_rand(1, 100) <= 48) {
            $keys = array_keys($this->exclusionGroups);
            shuffle($keys);
            $groups = array_slice($keys, 0, $this->pick([1 => 55, 2 => 30, 3 => 15]));
        }
        $p['replacements'] = $groups && mt_rand(1, 100) <= 62;

        $comment = [];
        if ($groups) {
            $comment[] = ucfirst(implode(', ', $groups));
        }
        if (mt_rand(1, 100) <= 25) {
            $comment[] = $this->prodComments[array_rand($this->prodComments)];
        }

        $client = Client::create([
            'name'               => ($female ? $f : $m) . ' ' . $first,
            'phone'              => $this->fakePhone(),
            'sales_source'       => mt_rand(1, 100) <= 5 ? null : $this->pick($this->sources),
            'target_kcal'        => $p['kcal'],
            'address'            => $address,
            'address_entrance'   => (string) mt_rand(1, 6),
            'address_apartment'  => (string) mt_rand(1, 220),
            'address_floor'      => (string) mt_rand(1, 24),
            'delivery_comment'   => mt_rand(1, 100) <= 6 ? ['Залишити біля дверей', 'Дзвонити за 10 хв', 'Код домофону в чаті', 'Передати консьєржу'][mt_rand(0, 3)] : null,
            'production_comment' => $comment ? implode('. ', $comment) : null,
            'menu_brief'         => mt_rand(1, 100) <= 16 ? $this->briefs[array_rand($this->briefs)] : null,
            'has_cutlery'        => mt_rand(1, 100) <= 65,
            'water_option'       => $this->pick(['with_water' => 70, 'without_water' => 27, 'water_without_lemon' => 3]),
        ]);

        $ingIds = [];
        foreach ($groups as $g) {
            array_push($ingIds, ...($this->exclusionPool[$g] ?? []));
        }
        if ($ingIds) {
            $client->ingredientExclusions()->syncWithoutDetaching(array_unique($ingIds));
        }
        if (mt_rand(1, 100) <= 13 && $this->dishesByMeal) {
            $all = array_merge(...array_values($this->dishesByMeal));
            $client->dishExclusions()->syncWithoutDetaching(array_slice(array_unique(array_map(fn () => $all[array_rand($all)], range(1, 3))), 0, mt_rand(1, 3)));
        }

        ClientAddress::create([
            'client_id' => $client->id, 'label' => 'Дім', 'address' => $address,
            'address_entrance' => $client->address_entrance, 'address_apartment' => $client->address_apartment,
            'address_floor' => $client->address_floor, 'is_default' => true,
        ]);
        if (mt_rand(1, 100) <= 10) {
            $work = $this->districts['Печерський'][1];
            ClientAddress::create([
                'client_id' => $client->id, 'label' => 'Робота', 'address' => $work[array_rand($work)] . ', ' . mt_rand(1, 60),
                'address_floor' => (string) mt_rand(2, 25), 'is_default' => false,
            ]);
        }

        $mealTypes = [7, 9, 11];
        foreach ([8 => 89, 10 => 86, 13 => 67] as $mt => $pct) {
            if (mt_rand(1, 100) <= $pct) {
                $mealTypes[] = $mt;
            }
        }
        foreach ($mealTypes as $mt) {
            DB::table('client_meal_type')->insert(['client_id' => $client->id, 'meal_type_id' => $mt, 'energy_percent' => 0]);
        }
        $p['meal_types'] = $mealTypes;

        return $client;
    }

    private function makeOrder(Client $client, array $p, array $o, bool $first, ?Order $parent = null): ?Order
    {
        $dates = $o['dates'];
        $duration = count($dates);
        $key = match (true) {
            $p['individual']   => 'ind',
            $first && $duration === 3 => 'trial',
            $duration <= 2     => 'd1',
            $duration <= 9     => 'd5',
            $duration <= 17    => 'd14',
            default            => 'd21',
        };
        $tariffId = $this->tariffMap[$p['project']][$key];
        $kcal = $parent ? $this->pick($this->kcal) : $p['kcal'];
        $kcal = $this->priceableKcal($tariffId, $kcal);
        if (! $kcal) {
            return null;
        }

        $time = $p['evening'] ? $this->pick($this->eveningTimes) : $this->pick($this->morningTimes);
        $discount = ! $parent && $o['discount'];

        $order = Order::create([
            'client_id'       => $client->id,
            'parent_order_id' => $parent?->id,
            'project'         => $p['project'],
            'tariff_id'       => $tariffId,
            'calories'        => $kcal,
            'kit_count'       => 1,
            'duration'        => $duration,
            'start_date'      => $dates[0],
            'end_date'        => end($dates),
            'schedule_type'   => $p['evening'] ? 'every_day_evening' : 'every_day_morning',
            'delivery_time'   => $time,
            'menu_type'       => $p['individual'] ? 'individual' : 'cyclic',
            'status'          => 'new',
            'discount_type'   => $discount ? ($first ? 'fixed' : 'percent') : null,
            'discount_value'  => $discount ? ($first ? 300 : 5) : null,
            'discount_reason' => $discount ? ($first ? 'Перше замовлення' : 'Постійний клієнт') : null,
        ]);

        foreach ($dates as $d) {
            OrderDay::create([
                'order_id'          => $order->id,
                'date'              => $d,
                'is_completed'      => Carbon::parse($d)->lt($this->today),
                'address'           => $client->address,
                'address_entrance'  => $client->address_entrance,
                'address_apartment' => $client->address_apartment,
                'address_floor'     => $client->address_floor,
                'delivery_comment'  => $client->delivery_comment,
                'delivery_time'     => $time,
            ]);
        }

        return $order->fresh();
    }

    /** Оплата: картка 81% / готівка 12% / рахунок ФОП 7%; ~6% стартувавших не оплачені. */
    private function pay(Order $order, Client $client, Carbon $start): void
    {
        // Старі борги давно закриті; ~6% не оплатили лише серед поточних.
        $end = Carbon::parse($order->end_date);
        $paid = match (true) {
            $end->lt($this->today->copy()->subDays(10)) => mt_rand(1, 1000) <= 997,
            $start->lte($this->today)                  => mt_rand(1, 100) <= 94,
            $start->lte($this->today->copy()->addDays(3)) => mt_rand(1, 100) <= 80,
            default                                    => mt_rand(1, 100) <= 35,
        };
        $total = (float) $order->final_price + (float) Order::where('parent_order_id', $order->id)->sum('final_price');
        if (! $paid || $total <= 0) {
            return;
        }

        $date = Carbon::parse($order->created_at)->addHours(mt_rand(1, 26));
        if ($date->gt($this->today->copy()->setTime(13, 0))) {
            $date = $this->today->copy()->setTime(mt_rand(9, 12), mt_rand(0, 59));
        }
        Carbon::setTestNow($date);

        Transaction::create([
            'client_id'  => $client->id,
            'order_id'   => $order->id,
            'account_id' => $this->accounts[$this->pick(['card' => 81, 'cash' => 12, 'online' => 7])],
            'type'       => 'income',
            'category'   => 'Оплата клієнта',
            'method'     => 'system',
            'amount'     => round($total, 2),
            'date'       => $date->toDateString(),
            'comment'    => "Оплата замовлення #{$order->id}",
        ]);

        $client->refresh()->syncBalance();
        $client->recalculateOrderPaymentStatus();
    }

    /** Заміни першого замовлення; продовження успадкують їх самі. */
    private function seedReplacements(Order $order, Client $client): void
    {
        $excluded = $client->ingredientExclusions()->pluck('ingredients.id')->all();
        $items = [];
        $subs = $this->substituteIngredients();
        foreach ($excluded as $ingId) {
            foreach (array_slice($this->dishesByIngredient[$ingId] ?? [], 0, 6) as $dishId) {
                $roll = mt_rand(1, 100);
                $items[$dishId . '-' . $ingId] = match (true) {
                    $roll <= 55 => ['dish_id' => $dishId, 'original_product_id' => $ingId, 'replacement_product_id' => $subs[array_rand($subs)]],
                    $roll <= 82 => ['dish_id' => $dishId, 'original_product_id' => $ingId, 'replacement_product_id' => null, 'comment' => 'Без продукту'],
                    default     => ['dish_id' => $dishId, 'replacement_dish_id' => $this->otherDish($dishId)],
                };
                if (isset($items[$dishId . '-' . $ingId]['replacement_dish_id'])) {
                    unset($items[$dishId . '-' . $ingId]['original_product_id']);
                }
            }
        }
        // Заміна всієї страви — одна на страву.
        $items = collect($items)->unique(fn ($it) => isset($it['replacement_dish_id']) ? 'd' . $it['dish_id'] : $it['dish_id'] . '-' . $it['original_product_id'])
            ->take(18)->values()->all();
        if (! $items) {
            return;
        }

        $payload = ['order_id' => $order->id, 'mode' => 'merge', 'items' => $items];
        $svc = app(OrderReplacements::class);
        if (! $svc->validate($payload)) {
            $svc->apply($payload);
        }
    }

    // ------------------------------------------------------------------
    // Персональні меню індивідуальних (~1/6 індивідуальних замовлень)
    // ------------------------------------------------------------------

    private function seedPersonalMenus(): void
    {
        $from = $this->today->copy()->subDays(self::WINDOW_PAST)->toDateString();
        $to = $this->today->copy()->addDays(7)->toDateString();
        $orders = Order::where('menu_type', 'individual')->whereNull('parent_order_id')
            ->where('end_date', '>=', $from)->where('start_date', '<=', $to)->get();

        foreach ($orders as $order) {
            if (mt_rand(1, 100) > 18) {
                continue;
            }
            $meals = DB::table('client_meal_type')->where('client_id', $order->client_id)->pluck('meal_type_id')->all();
            $dates = $order->orderDays()->whereBetween('date', [$from, $to])->pluck('date');
            Carbon::setTestNow(Carbon::parse($order->start_date)->subDay()->setTime(15, 0));
            foreach ($dates as $date) {
                foreach ($meals as $mt) {
                    $pool = $this->dishesByMeal[$mt] ?? [];
                    if (! $pool) {
                        continue;
                    }
                    OrderDayDish::create([
                        'order_id'     => $order->id,
                        'date'         => Carbon::parse($date)->toDateString(),
                        'meal_type_id' => $mt,
                        'dish_id'      => $pool[array_rand($pool)],
                        // Грамівку рахує CRM під калораж.
                        'weight_grams' => null,
                    ]);
                }
            }
        }
    }

    // ------------------------------------------------------------------
    // Логістика: маршрути RouteLogix → наліпки, виїзди, табель курʼєрів
    // ------------------------------------------------------------------

    private function seedRoutes(): void
    {
        $svc = app(RouteLogixService::class);
        $districtOrder = array_flip(array_keys($this->districts));
        $districtOf = [];
        foreach (Client::query()->get(['id', 'address']) as $c) {
            $districtOf[$c->id] = $this->districtOfAddress($c->address, $districtOrder);
        }

        $from = $this->today->copy()->subDays(self::OPS_DAYS);
        // Логіст уже побудував вечір сьогодні й ранок завтра.
        $slots = [];
        for ($d = $from->copy(); $d->lte($this->today); $d->addDay()) {
            $slots[] = [$d->toDateString(), 'morning'];
            $slots[] = [$d->toDateString(), 'evening'];
        }
        $slots[] = [$this->today->copy()->addDay()->toDateString(), 'morning'];

        foreach ($slots as $k => [$date, $shift]) {
            Carbon::setTestNow(Carbon::parse($date)->setTime($shift === 'morning' ? 5 : 15, 30));
            $days = app(\App\Services\AntLogisticsService::class)->collectOrderDaysForDelivery($date, $shift)['days'];
            if ($days->isEmpty()) {
                continue;
            }

            // Точка = клієнт (сімейні раціони — одна зупинка), групуємо по районах.
            $stops = $days->groupBy(fn ($d) => $d->order->client_id)
                ->sortBy(fn ($g, $clientId) => sprintf('%02d-%s', $districtOf[$clientId] ?? 99, $g->first()->address))
                ->values();
            $routesCount = max(1, (int) ceil($stops->count() / 11));
            $perRoute = (int) ceil($stops->count() / $routesCount);
            $couriers = $this->couriersFor($date, $shift, $routesCount);

            $planStops = [];
            foreach ($stops->chunk($perRoute)->values() as $r => $chunk) {
                $courier = $couriers[$r % count($couriers)];
                $this->ensureCourierShift($courier, $date);
                $eta = Carbon::parse($date)->setTime($shift === 'morning' ? 6 : 18, mt_rand(0, 20));
                foreach ($chunk->values() as $pos => $group) {
                    $planStops[] = [
                        'route_num'     => $r + 1,
                        'route_pos'     => $pos + 1,
                        'driver'        => $courier['name'],
                        'employee_id'   => $courier['id'],
                        'order_day_ids' => $group->pluck('id')->all(),
                        'eta'           => $eta->addMinutes(mt_rand(9, 17))->format('H:i'),
                    ];
                }
            }

            $this->logistPlan = [
                'plan_id' => 1000 + $k, 'date' => $date, 'shift' => $shift,
                'published_at' => now()->toIso8601String(), 'edited_after' => false, 'stops' => $planStops,
            ];
            $svc->import($date, $shift);
        }

        $this->seedMileage($from);
    }

    /** Курʼєри зміни: частина возить і ранок, і вечір, решта — одну зміну. */
    private function couriersFor(string $date, string $shift, int $count): array
    {
        $pool = $this->couriers;
        $offset = (Carbon::parse($date)->dayOfYear * 7 + ($shift === 'evening' ? 11 : 0)) % count($pool);
        $rotated = array_merge(array_slice($pool, $offset), array_slice($pool, 0, $offset));

        return array_slice($rotated, 0, min($count, count($rotated)));
    }

    private function ensureCourierShift(array $courier, string $date): void
    {
        if (EmployeeShift::where('employee_id', $courier['id'])->whereDate('date', $date)->exists()) {
            return;
        }
        $emp = Employee::find($courier['id']);
        $rate = (float) ($emp->rateOn($date) ?? $emp->base_rate);
        EmployeeShift::create([
            'employee_id' => $emp->id, 'date' => $date, 'shift_slot' => EmployeeShift::SLOT_FULL,
            'rate' => $rate, 'is_duty' => false, 'is_half' => false, 'is_planned' => false, 'position_key' => 'courier',
        ]);
        $emp->increment('balance', $rate);
    }

    private function seedMileage(Carbon $from): void
    {
        $routes = DB::table('delivery_routes')->whereNotNull('employee_id')->where('date', '<', $this->today->toDateString())
            ->select('employee_id', 'date', DB::raw('count(*) n'), DB::raw('sum(count_comps) stops'))->groupBy('employee_id', 'date')->get();
        $odo = [];
        foreach ($routes as $r) {
            $start = $odo[$r->employee_id] ??= mt_rand(40000, 180000);
            $km = (int) ($r->stops * mt_rand(38, 55) / 10 + 18 * $r->n);
            Carbon::setTestNow(Carbon::parse($r->date)->setTime(22, 30));
            CourierMileageLog::create([
                'employee_id' => $r->employee_id, 'date' => $r->date, 'shift_slot' => 'full',
                'start_km' => $start, 'end_km' => $start + $km,
                'fuel_price_per_liter' => 58.5, 'fuel_consumption' => 7.5, 'amort_per_km' => 1.2, 'mileage_unit' => 'km',
            ]);
            $odo[$r->employee_id] = $start + $km + mt_rand(5, 30);
        }
    }

    // ------------------------------------------------------------------
    // Табель, бонуси/штрафи, виплати ЗП
    // ------------------------------------------------------------------

    private function seedAttendanceAndPayroll(): void
    {
        $from = $this->today->copy()->subDays(self::OPS_DAYS);
        $perDay = ['cook' => 16, 'packer' => 5, 'manager' => 4, 'cleaner' => 2];

        for ($d = $from->copy(); $d->lte($this->today->copy()->addDays(6)); $d->addDay()) {
            $date = $d->toDateString();
            $planned = $d->gt($this->today);
            Carbon::setTestNow($d->copy()->setTime(20, 0));
            foreach ($perDay as $pos => $n) {
                if ($d->isSaturday() && $pos !== 'manager') {
                    continue; // у суботу кухня не готує
                }
                $staff = $this->staffByPosition[$pos] ?? [];
                shuffle($staff);
                foreach (array_slice($staff, 0, $n) as $emp) {
                    $rate = (float) ($emp->rateOn($date) ?? $emp->base_rate);
                    EmployeeShift::create([
                        'employee_id' => $emp->id, 'date' => $date, 'shift_slot' => EmployeeShift::SLOT_FULL,
                        'rate' => $rate, 'is_duty' => false, 'is_half' => false, 'is_planned' => $planned, 'position_key' => $pos,
                    ]);
                    if (! $planned) {
                        $emp->increment('balance', $rate);
                    }
                }
            }

            // Бонуси/штрафи — по кілька на тиждень.
            if (! $planned && mt_rand(1, 100) <= 45) {
                $cook = $this->staffByPosition['cook'][array_rand($this->staffByPosition['cook'])];
                EmployeeBonus::create(['employee_id' => $cook->id, 'amount' => 300, 'reason' => 'Чергування на кухні', 'date' => $date]);
            }
            if (! $planned && mt_rand(1, 100) <= 20) {
                $c = $this->couriers[array_rand($this->couriers)];
                EmployeePenalty::create(['employee_id' => $c['id'], 'amount' => 200, 'reason' => 'Запізнення на маршрут', 'date' => $date]);
            }

            // Щопонеділка виплата за минулий тиждень.
            if (! $planned && $d->isMonday() && $d->lt($this->today)) {
                foreach (Employee::where('is_active', true)->where('balance', '>', 0)->get() as $emp) {
                    Transaction::create([
                        'employee_id' => $emp->id,
                        'account_id'  => $this->payFrom((float) $emp->balance, mt_rand(1, 100) <= 25),
                        'amount'      => round((float) $emp->balance),
                        'type'        => 'expense',
                        'category'    => 'Виплата ЗП',
                        'date'        => $date,
                        'comment'     => "Виплата ЗП: {$emp->name}",
                    ]);
                }
            }
        }
    }

    // ------------------------------------------------------------------
    // Склад: приходи під план і списання за нормою (KitchenStockDebit)
    // ------------------------------------------------------------------

    private function seedStock(): void
    {
        $debit = app(KitchenStockDebit::class);
        $warehouseId = DB::table('warehouses')->value('id');
        $supplierIds = DB::table('suppliers')->pluck('id')->all();
        $from = $this->today->copy()->subDays(self::OPS_DAYS);

        for ($cook = $from->copy(); $cook->lt($this->today); $cook->addDay()) {
            Carbon::setTestNow($cook->copy()->setTime(8, 0));
            $plan = $debit->plan($cook);
            if (! $plan['lines']) {
                continue;
            }

            // Приходи під потребу з запасом, 3–5 накладних на день.
            $lines = collect($plan['lines'])->where('kind', 'ingredient')->shuffle()->values();
            $docs = mt_rand(3, 5);
            foreach ($lines->chunk((int) ceil(max(1, $lines->count()) / $docs)) as $n => $chunk) {
                $at = $cook->copy()->setTime(7 + $n, mt_rand(0, 59));
                Carbon::setTestNow($at);
                $ai = mt_rand(1, 100) <= 45;
                $doc = StockDocument::create([
                    'type'           => 'receipt',
                    'warehouse_id'   => $warehouseId,
                    'supplier_id'    => $supplierIds[array_rand($supplierIds)],
                    'account_id'     => $this->accounts[mt_rand(1, 100) <= 15 ? 'online' : 'card'],
                    'operation_date' => $at,
                    'status'         => StockDocument::STATUS_DRAFT,
                    'source'         => $ai ? StockDocument::SOURCE_AI : StockDocument::SOURCE_MANUAL,
                    'invoice_number' => 'ВН-' . mt_rand(10000, 99999),
                    'invoice_date'   => $at->toDateString(),
                    'is_paid'        => mt_rand(1, 100) <= 90,
                ]);
                foreach ($chunk as $line) {
                    $ing = Ingredient::find($line['id']);
                    $qty = $this->roundUpPack((float) $line['qty'] * mt_rand(108, 135) / 100);
                    $price = round(max(1, (float) ($ing->price_per_kg ?: $line['price'])) * mt_rand(95, 108) / 100, 2);
                    StockDocumentItem::create([
                        'stock_document_id' => $doc->id,
                        'itemable_type'     => Ingredient::class,
                        'itemable_id'       => $ing->id,
                        'qty'               => $qty,
                        'price'             => $price,
                        'total_price'       => round($qty * $price, 2),
                    ]);
                }
                $doc->post();
            }

            Carbon::setTestNow($cook->copy()->setTime(21, 0));
            try {
                $debit->post($debit->plan($cook));
            } catch (\RuntimeException $e) {
                $this->say("  списання {$cook->toDateString()}: " . $e->getMessage());
            }
        }

        // Свіжа чернетка, розпізнана ШІ з фото, — чекає проведення.
        Carbon::setTestNow($this->today->copy()->setTime(9, 40));
        $draft = StockDocument::create([
            'type' => 'receipt', 'warehouse_id' => $warehouseId, 'supplier_id' => $supplierIds[0],
            'operation_date' => now(), 'status' => StockDocument::STATUS_DRAFT, 'source' => StockDocument::SOURCE_AI,
            'invoice_number' => 'ВН-' . mt_rand(10000, 99999), 'invoice_date' => $this->today->toDateString(),
            'ai_comment' => 'Розпізнано з фото: 8 позицій, усі знайдено в довіднику. Філе куряче подорожчало на 7% від минулої закупівлі.',
        ]);
        foreach (Ingredient::where('price_per_kg', '>', 0)->inRandomOrder()->limit(8)->get() as $ing) {
            $qty = round(mt_rand(50, 400) / 10, 1);
            StockDocumentItem::create([
                'stock_document_id' => $draft->id, 'itemable_type' => Ingredient::class, 'itemable_id' => $ing->id,
                'qty' => $qty, 'price' => $ing->price_per_kg, 'total_price' => round($qty * $ing->price_per_kg, 2),
            ]);
        }
        $draft->updateTotalSum();
    }

    /** Округлення закупівлі лише вгору: кг до 0.5, штуки цілими. */
    private function roundUpPack(float $qty): float
    {
        return $qty < 3 ? ceil($qty * 10) / 10 : ceil($qty * 2) / 2;
    }

    /** Витрати каси: до вікна складу — закупівля й ЗП підсумками, далі непрофільні статті. */
    private function seedCashExpenses(): void
    {
        $from = $this->today->copy()->subDays(self::HISTORY_DAYS);
        $opsFrom = $this->today->copy()->subDays(self::OPS_DAYS);
        $rations = DB::table('order_days')->where('date', '<', $opsFrom->toDateString())
            ->groupBy('date')->pluck(DB::raw('count(*)'), 'date');
        for ($d = $from->copy(); $d->lt($this->today); $d->addDay()) {
            $rows = [];
            if ($d->lt($opsFrom)) {
                $n = (int) ($rations[$d->toDateString()] ?? 0);
                if ($n > 0 && ! $d->isSunday()) {
                    $rows[] = ['Закупівля без накладної', round($n * mt_rand(250, 300) * ($d->isFriday() ? 1.6 : 1)), 'Закупівля продуктів (до обліку складу)'];
                }
                if ($d->isMonday()) {
                    $rows[] = ['Виплата ЗП', mt_rand(400, 450) * 1000, 'ЗП за тиждень за відомістю'];
                }
            }
            if ($d->day === 1) {
                $rows[] = ['Оренда', 140000, 'Оренда цеху й офісу'];
                $rows[] = ['Податки та банк', mt_rand(55000, 75000), 'ЄП, ЄСВ, обслуговування рахунків'];
            }
            if ($d->day === 10) {
                $rows[] = ['Комунальні', mt_rand(32000, 45000), 'Електроенергія, вода, газ'];
            }
            if ($d->isMonday()) {
                $rows[] = ['Реклама', mt_rand(22000, 32000), 'Meta Ads + Google Ads'];
                $rows[] = ['Господарські', mt_rand(3000, 6000), 'Миючі, рукавички, плівка'];
            }
            if (mt_rand(1, 100) <= 12) {
                $rows[] = ['Генератор', mt_rand(6000, 14000), 'Пальне для генератора'];
            }
            if (mt_rand(1, 100) <= 4) {
                $rows[] = ['Обладнання та ремонт', mt_rand(5000, 25000), 'Ремонт пароконвектомата'];
            }
            foreach ($rows as [$cat, $sum, $comment]) {
                Carbon::setTestNow($d->copy()->setTime(mt_rand(10, 17), mt_rand(0, 59)));
                Transaction::create([
                    'account_id' => in_array($cat, ['Оренда', 'Податки та банк', 'Комунальні'], true) ? $this->accounts['online'] : $this->payFrom($sum, $cat === 'Виплата ЗП' && mt_rand(1, 100) <= 25),
                    'amount' => $sum, 'type' => 'expense', 'category' => $cat,
                    'date' => $d->toDateString(), 'comment' => $comment,
                ]);
            }
        }
    }

    /**
     * Власник виводить прибуток раз на тиждень — інакше на рахунках
     * накопичується вся виручка за історію.
     */
    private function seedOwnerWithdrawals(): void
    {
        $targets = ['card' => 2600000, 'online' => 450000, 'cash' => 120000];
        $fridays = [];
        for ($d = $this->today->copy()->subDays(self::HISTORY_DAYS); $d->lt($this->today->copy()->subDays(2)); $d->addDay()) {
            if ($d->isFriday()) {
                $fridays[] = $d->copy();
            }
        }
        $fridays[] = $this->today->copy()->subDay();
        foreach ($targets as $type => $target) {
            $id = $this->accounts[$type];
            foreach ($fridays as $f) {
                $balance = (float) DB::table('transactions')->where('account_id', $id)->where('date', '<=', $f->toDateString())
                    ->selectRaw("sum(case when type='income' then amount else -amount end) b")->value('b');
                $sum = round($balance - $target * mt_rand(85, 115) / 100, -3);
                if ($sum <= 0) {
                    continue;
                }
                Carbon::setTestNow($f->copy()->setTime(18, 30));
                Transaction::create([
                    'account_id' => $id, 'amount' => $sum, 'type' => 'expense',
                    'category' => 'Вилучення власником', 'date' => $f->toDateString(), 'comment' => 'Вивід прибутку',
                ]);
            }
        }

        // Рахунок у мінусі — поповнюємо з картки переказом, як зробив би бухгалтер.
        foreach (['cash', 'online'] as $type) {
            $balance = $this->accountBalance($this->accounts[$type]);
            if ($balance < 20000) {
                Carbon::setTestNow($this->today->copy()->subDay()->setTime(19, 0));
                \App\Services\Accounting\CashBook::transfer($this->accounts['card'], $this->accounts[$type], round($targets[$type] - $balance, -3), now()->toDateString(), 'Поповнення');
            }
        }
    }

    private function accountBalance(int $id, ?string $upTo = null): float
    {
        return (float) DB::table('transactions')->where('account_id', $id)
            ->when($upTo, fn ($q) => $q->where('date', '<=', $upTo))
            ->selectRaw("sum(case when type='income' then amount else -amount end) b")->value('b');
    }

    /** Готівкою — лише якщо в касі вистачає, інакше з картки. */
    private function payFrom(float $amount, bool $preferCash): int
    {
        if ($preferCash && $this->accountBalance($this->accounts['cash'], now()->toDateString()) >= $amount) {
            return $this->accounts['cash'];
        }

        return $this->accounts['card'];
    }

    // ------------------------------------------------------------------
    // Після перерахунку статусів: паузи, дзвінки, оцінки
    // ------------------------------------------------------------------

    /** Клієнт попросив паузу: майбутні дні знімаємо, статус paused (sticky). */
    private function seedPauses(): void
    {
        $orders = Order::where('status', 'active')->whereNull('parent_order_id')
            ->where('start_date', '<', $this->today)->where('end_date', '>', $this->today->copy()->addDays(3))
            ->inRandomOrder()->limit(7)->get();
        foreach ($orders as $order) {
            $order->orderDays()->whereDate('date', '>', $this->today->copy()->addDay())->get()->each->delete();
            $order->refresh()->update(['status' => 'paused', 'comment' => 'Пауза на прохання клієнта (відпустка), дні перенесемо']);
        }
    }

    /** Дзвінки утримання по замовленнях, що закінчились чи закінчуються. */
    private function seedCalls(): void
    {
        $managerIds = User::where('role', 'manager')->pluck('id')->all();
        $orders = Order::whereNull('parent_order_id')
            ->whereBetween('end_date', [$this->today->copy()->subDays(self::OPS_DAYS)->toDateString(), $this->today->copy()->addDays(3)->toDateString()])
            ->get();

        foreach ($orders as $order) {
            $renewed = Order::where('client_id', $order->client_id)->where('id', '>', $order->id)->whereNull('parent_order_id')->exists();
            $past = Carbon::parse($order->end_date)->lt($this->today->copy()->subDays(1));
            if ($past && ! $renewed && mt_rand(1, 100) <= 15) {
                continue; // частину не встигли прозвонити
            }
            $status = match (true) {
                $renewed => 'success',
                $past    => $this->pick(['refused' => 45, 'thinking' => 35, 'no_answer' => 20]),
                default  => $this->pick(['new' => 45, 'thinking' => 30, 'no_answer' => 10, 'refused' => 5, 'success' => 10]),
            };
            $reason = $status === 'refused' ? $this->pick(['vacation_business' => 30, 'expensive' => 22, 'pause' => 20, 'out_of_city' => 10, 'try_other' => 10, 'taste' => 8]) : null;
            Carbon::setTestNow(Carbon::parse($order->end_date)->subDays(mt_rand(0, 2))->setTime(mt_rand(10, 18), mt_rand(0, 59))->min($this->today->copy()->setTime(12, 0)));
            OrderCall::create([
                'order_id'       => $order->id,
                'client_id'      => $order->client_id,
                'status'         => $status,
                'comment'        => match ($status) {
                    'thinking'  => ['Подумає до вечора, цікавиться 14 днями', 'Порадиться з чоловіком', 'Хоче спробувати інший калораж'][mt_rand(0, 2)],
                    'success'   => ['Продовжує на тиждень', 'Продовжила на 14 днів', 'Продовжує, той самий калораж'][mt_rand(0, 2)],
                    'refused'   => OrderCall::refusalReasonLabel($reason),
                    'no_answer' => 'Не взяли слухавку, написали в Telegram',
                    default     => null,
                },
                'refusal_reason' => $reason,
                'next_call_at'   => in_array($status, ['thinking', 'no_answer'], true) ? $this->today->copy()->addDays(mt_rand(0, 2))->setTime(11, 0) : null,
                'user_id'        => $managerIds[array_rand($managerIds)],
            ]);
        }
        Carbon::setTestNow();
    }

    private function seedRatings(): void
    {
        $comments = ['Дуже смачно, дякую!', 'Сирники — топ', 'Трохи пересолено', 'Хотілося б більше соусу', 'Порція ідеальна', 'Найкращий сніданок за тиждень', 'Овочі могли б бути хрусткішими', 'Курка суха', null, null, null, null];
        $days = OrderDay::query()->join('orders', 'orders.id', '=', 'order_days.order_id')
            ->whereBetween('order_days.date', [$this->today->copy()->subDays(self::OPS_DAYS)->toDateString(), $this->today->copy()->subDay()->toDateString()])
            ->inRandomOrder()->limit(70)->get(['order_days.order_id', 'order_days.date']);
        $all = array_merge(...array_values($this->dishesByMeal));
        foreach ($days as $day) {
            foreach ((array) array_rand(array_flip(array_unique($all)), mt_rand(2, 4)) as $dishId) {
                DishRating::firstOrCreate([
                    'order_id' => $day->order_id,
                    'dish_id'  => $dishId,
                    'date'     => $day->date,
                ], [
                    'stars'    => $this->pick([5 => 66, 4 => 18, 3 => 7, 2 => 8, 1 => 1]),
                    'comment'  => $comments[array_rand($comments)],
                ]);
            }
        }
    }

    // ------------------------------------------------------------------
    // Персонал і рахунки
    // ------------------------------------------------------------------

    private function seedStaff(): void
    {
        $password = env('NUTRIX_DEMO_PASSWORD') ?: Str::random(20);
        $users = [
            ['Адміністратор', 'demo@nutrix.test', 'admin'],
            ['Власник', 'owner@nutrix.test', 'admin'],
            ['Кухня', 'cook@nutrix.test', 'cook'],
        ];
        foreach (['Олена Коваль', 'Ірина Мельник', 'Марта Гуменюк', 'Софія Левченко', 'Дарина Остапчук', 'Вікторія Зінченко'] as $i => $name) {
            $users[] = [$name, 'manager' . ($i ?: '') . '@nutrix.test', 'manager'];
        }
        foreach ($users as [$name, $email, $role]) {
            User::updateOrCreate(['email' => $email], ['name' => $name, 'password' => $password, 'role' => $role]);
        }

        $counts = ['courier' => 30, 'cook' => 26, 'packer' => 6, 'manager' => 6, 'cleaner' => 3, 'buxgalter' => 1, 'targetolog' => 2, 'dizainer' => 1];
        $rates = ['courier' => 850, 'cook' => 1650, 'packer' => 1500, 'manager' => 1200, 'cleaner' => 1100, 'buxgalter' => 25000, 'targetolog' => 30000, 'dizainer' => 22000];
        $used = [];
        foreach ($counts as $position => $n) {
            for ($i = 0; $i < $n; $i++) {
                do {
                    $female = $position === 'courier' ? mt_rand(1, 100) <= 10 : mt_rand(1, 100) <= 70;
                    [$m, $f] = $this->surnames[array_rand($this->surnames)];
                    $name = $female ? $this->female[array_rand($this->female)] . ' ' . $f : $this->male[array_rand($this->male)] . ' ' . $m;
                } while (isset($used[$name]));
                $used[$name] = true;
                $emp = Employee::create([
                    'name'             => $name,
                    'position'         => $position,
                    'phone'            => $this->fakePhone(),
                    'base_rate'        => $rates[$position],
                    'fuel_consumption' => $position === 'courier' ? 7.5 : null,
                    'is_active'        => true,
                ]);
                $this->staffByPosition[$position][] = $emp;
                if ($position === 'courier') {
                    $this->couriers[] = ['id' => $emp->id, 'name' => $emp->name];
                }
            }
        }
    }

    private function seedAccounts(): void
    {
        foreach ([['Каса (готівка)', 'cash'], ['Рахунок ФОП', 'online'], ['Картка monobank', 'card']] as [$name, $type]) {
            $this->accounts[$type] = Account::create(['name' => $name, 'type' => $type, 'balance' => 0, 'is_default' => $type === 'card'])->id;
        }

        // P&L бере оренду й комунальні з налаштувань (датована ставка — з початку історії).
        Carbon::setTestNow($this->today->copy()->subDays(self::HISTORY_DAYS + 30));
        \App\Models\Setting::updateOrCreate(['key' => 'monthly_rent'], ['value' => '140000']);
        \App\Models\Setting::updateOrCreate(['key' => 'monthly_utilities'], ['value' => '40000']);
        Carbon::setTestNow();
    }

    // ------------------------------------------------------------------
    // Довідники меню
    // ------------------------------------------------------------------

    private function loadMenuIndex(): void
    {
        foreach (DB::table('daily_menu_dishes')->get(['meal_type_id', 'dish_id']) as $r) {
            $this->dishesByMeal[$r->meal_type_id][] = $r->dish_id;
        }
        foreach ($this->dishesByMeal as $mt => $ids) {
            $this->dishesByMeal[$mt] = array_values(array_unique($ids));
        }
        $cycleDishes = array_merge(...array_values($this->dishesByMeal));
        foreach (DB::table('dish_ingredients')->whereIn('dish_id', $cycleDishes)->get(['dish_id', 'ingredient_id']) as $r) {
            $this->dishesByIngredient[$r->ingredient_id][] = $r->dish_id;
        }

        foreach ($this->exclusionGroups as $group => $patterns) {
            $this->exclusionPool[$group] = Ingredient::where(function ($q) use ($patterns) {
                foreach ($patterns as $p) {
                    $q->orWhere('name', 'like', $p . '%')->orWhere('name', 'like', '% ' . mb_strtolower($p) . '%');
                }
            })->limit(14)->pluck('id')->all();
        }
    }

    private function substituteIngredients(): array
    {
        static $ids;

        return $ids ??= Ingredient::where(function ($q) {
            foreach (['Філе курк', 'Індичк', 'Броколі', 'Кабачок', 'Рис', 'Гречк', 'Огірок', 'Шпинат', 'Батат', 'Сир кисломолочний'] as $p) {
                $q->orWhere('name', 'like', $p . '%');
            }
        })->pluck('id')->all() ?: Ingredient::limit(10)->pluck('id')->all();
    }

    private function otherDish(int $dishId): int
    {
        foreach ($this->dishesByMeal as $ids) {
            if (in_array($dishId, $ids, true)) {
                $other = array_values(array_diff($ids, [$dishId]));

                return $other ? $other[array_rand($other)] : $dishId;
            }
        }

        return $dishId;
    }

    private function districtOfAddress(?string $address, array $order): int
    {
        foreach ($this->districts as $name => [, $streets]) {
            foreach ($streets as $s) {
                if ($address && str_starts_with($address, $s . ',')) {
                    return $order[$name];
                }
            }
        }

        return 99;
    }

    /** Калораж, для якого в тарифі є ненульова ціна; інакше найближчий такий. */
    private function priceableKcal(int $tariffId, int $kcal): ?int
    {
        static $cache = [];
        $ranges = $cache[$tariffId] ??= DB::table('tariff_prices as tp')
            ->join('calorie_ranges as cr', 'cr.id', '=', 'tp.calorie_range_id')
            ->where('tp.tariff_id', $tariffId)
            ->where('tp.price_per_day', '>', 0)
            ->get(['cr.min_kcal', 'cr.max_kcal']);
        if ($ranges->isEmpty()) {
            return null;
        }
        foreach ($ranges as $r) {
            if ($kcal >= $r->min_kcal && $kcal <= $r->max_kcal) {
                return $kcal;
            }
        }

        return (int) $ranges->sortBy(fn ($r) => abs($r->max_kcal - $kcal))->first()->max_kcal;
    }

    private function fakePhone(): string
    {
        // Префікс 555 — свідомо неіснуючий номер.
        $this->phoneSeq++;

        return '+380' . ['67', '50', '63', '93', '97'][$this->phoneSeq % 5] . '555' . str_pad((string) ($this->phoneSeq % 10000), 4, '0', STR_PAD_LEFT);
    }

    private function pick(array $weights): int|string
    {
        $roll = mt_rand(1, array_sum($weights));
        foreach ($weights as $value => $w) {
            if (($roll -= $w) <= 0) {
                return $value;
            }
        }

        return array_key_first($weights);
    }

    private function say(string $line): void
    {
        $this->command?->getOutput()->writeln($line);
    }
}
