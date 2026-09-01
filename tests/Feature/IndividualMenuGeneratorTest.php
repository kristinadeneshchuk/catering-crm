<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Order;
use App\Models\OrderDayDish;
use App\Services\Menu\IndividualMenuGenerator;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\Support\BuildsInboxTestSchema;
use Tests\TestCase;

/**
 * Підбір персонального меню за брифом клієнта.
 *
 * Модель обирає лише страви — і лише ті, що справді є в базі. Усе, що вона
 * вигадала, має відлітати: приготувати неіснуючу страву неможливо, а мовчазне
 * збереження такого id зламало б виробничий лист.
 */
class IndividualMenuGeneratorTest extends TestCase
{
    use BuildsInboxTestSchema;

    protected Client $client;
    protected Order $order;
    protected array $dish = [];
    protected array $mealType = [];
    protected array $ingredient = [];

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.openai.key', 'test-key');
        config()->set('services.openai.menu_model', 'gpt-4o');

        $this->buildInboxSchema();
        $this->buildMenuSchema();
        $this->seedCatalog(pricePerDay: 1000);

        // MenuPlan::default() кешується статично на весь процес і переживає
        // навіть інші тестові класи — скидаємо перед своїм планом.
        \App\Models\MenuPlan::forgetDefault();

        DB::table('menu_plans')->insert([
            'id' => 1, 'name' => 'Стандарт', 'is_default' => true,
            'cycle_days' => 28, 'cycle_start_date' => '2026-08-27',
        ]);

        // Стандарт для 1600 ккал: сніданок, обід, вечеря (спрощено на 3 прийоми).
        $this->mealType['breakfast'] = $this->makeMealType('Сніданок', 1);
        $this->mealType['lunch']     = $this->makeMealType('Обід', 3);
        $this->mealType['dinner']    = $this->makeMealType('Вечеря', 5);

        DB::table('meal_plans')->insert([
            'id' => 1, 'name' => 'Стандарт', 'min_kcal' => 0, 'max_kcal' => 9999,
        ]);
        foreach ($this->mealType as $id) {
            DB::table('meal_plan_meal_type')->insert(['meal_plan_id' => 1, 'meal_type_id' => $id]);
        }

        $chicken = $this->makeIngredient('Куряче філе');
        $beet    = $this->makeIngredient('Буряк');
        $salt    = $this->makeIngredient('Сіль');

        $this->dish['omelette'] = $this->makeDish('Омлет з овочами');
        // Курка вже в роботі на кухні, сіль дрібна — у промпт не піде.
        $this->dish['soup']     = $this->makeDish('Курячий суп', ingredients: [$chicken => 120, $salt => 2]);
        $this->dish['fish']     = $this->makeDish('Запечена риба', ingredients: [$beet => 80]);
        $this->dish['stock']    = $this->makeDish('Бульйон н/ф', semiFinished: true);

        $this->ingredient = compact('chicken', 'beet', 'salt');

        $clientId = $this->makeClient([
            'target_kcal' => 1600,
            'menu_brief'  => "Вік 29, ціль схуднення.\nНе їм: буряк, кефір.\nЛюблю: курку, рибу.",
        ]);

        $this->client = Client::find($clientId);
        foreach ($this->mealType as $id) {
            DB::table('client_meal_type')->insert(['client_id' => $clientId, 'meal_type_id' => $id]);
        }

        $this->order = Order::create([
            'client_id' => $clientId, 'project' => 'afood', 'menu_type' => 'individual',
            'tariff_id' => 1, 'calories' => 1600, 'duration' => 1,
            'start_date' => '2026-08-27', 'end_date' => '2026-08-27', 'scale_factor' => 1.0,
        ]);
    }

    /** Таблиці меню поверх базової схеми. */
    protected function buildMenuSchema(): void
    {
        // У спільній схемі страва — це лише id та назва.
        Schema::table('dishes', function (Blueprint $t) {
            $t->boolean('is_semi_finished')->default(false);
            $t->float('base_weight_g')->nullable();
        });

        Schema::create('meal_types', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->integer('sort_order')->default(1);
            $t->string('color')->nullable();
            $t->string('short_letter')->nullable();
            $t->float('energy_percent')->default(0);
            $t->timestamps();
        });

        Schema::create('meal_plans', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->integer('min_kcal');
            $t->integer('max_kcal');
            $t->timestamps();
        });

        Schema::create('meal_plan_meal_type', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('meal_plan_id');
            $t->unsignedBigInteger('meal_type_id');
            $t->integer('sort_order')->nullable();
        });

        Schema::create('client_meal_type', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('client_id');
            $t->unsignedBigInteger('meal_type_id');
        });

        Schema::create('dish_ingredients', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('dish_id');
            $t->unsignedBigInteger('ingredient_id')->nullable();
            $t->unsignedBigInteger('child_dish_id')->nullable();
            $t->float('net_weight_g')->default(0);
        });

        Schema::create('daily_menu_dishes', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('daily_menu_id');
            $t->unsignedBigInteger('dish_id');
            $t->unsignedBigInteger('meal_type_id');
            $t->float('custom_energy_percent')->nullable();
        });

        Schema::create('daily_menus', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('menu_plan_id')->nullable();
            $t->integer('day_number')->nullable();
            $t->timestamps();
        });

        Schema::create('order_day_dishes', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('order_id');
            $t->date('date');
            $t->unsignedBigInteger('meal_type_id');
            $t->unsignedBigInteger('dish_id')->nullable();
            $t->integer('weight_grams')->nullable();
            $t->text('cooking_note')->nullable();
            $t->timestamps();
        });
    }

    protected function makeMealType(string $name, int $sort): int
    {
        return DB::table('meal_types')->insertGetId([
            'name' => $name, 'sort_order' => $sort, 'energy_percent' => 30,
        ]);
    }

    protected function makeDish(string $name, bool $semiFinished = false, array $ingredients = []): int
    {
        $id = DB::table('dishes')->insertGetId([
            'name' => $name, 'is_semi_finished' => $semiFinished, 'base_weight_g' => 200,
        ]);

        foreach ($ingredients as $ingredientId => $grams) {
            DB::table('dish_ingredients')->insert([
                'dish_id' => $id, 'ingredient_id' => $ingredientId, 'net_weight_g' => $grams,
            ]);
        }

        return $id;
    }

    protected function makeIngredient(string $name): int
    {
        return DB::table('ingredients')->insertGetId(['name' => $name]);
    }

    /** Тіло відповіді моделі. */
    protected function aiBody(array $meals): array
    {
        return ['choices' => [['message' => ['content' => json_encode(['meals' => $meals])]]]];
    }

    /** Відповідь моделі. */
    protected function fakeAi(array $meals): void
    {
        Http::fake(['api.openai.com/*' => Http::response($this->aiBody($meals))]);
    }

    protected function generate(): array
    {
        return app(IndividualMenuGenerator::class)->generate($this->order, '2026-08-27');
    }

    // --- нормальний хід ----------------------------------------------------

    public function test_it_saves_the_picked_dishes(): void
    {
        $this->fakeAi([
            ['meal_type_id' => $this->mealType['breakfast'], 'dish_id' => $this->dish['omelette']],
            ['meal_type_id' => $this->mealType['lunch'],     'dish_id' => $this->dish['soup']],
            ['meal_type_id' => $this->mealType['dinner'],    'dish_id' => $this->dish['fish']],
        ]);

        $result = $this->generate();

        $this->assertSame(3, $result['assigned']);
        $this->assertSame([], $result['skipped']);
        $this->assertSame(3, OrderDayDish::where('order_id', $this->order->id)->count());

        $lunch = OrderDayDish::where('order_id', $this->order->id)
            ->where('meal_type_id', $this->mealType['lunch'])
            ->first();

        $this->assertSame($this->dish['soup'], $lunch->dish_id);
        $this->assertSame('2026-08-27', $lunch->date->toDateString());
    }

    public function test_weight_is_left_to_the_crm(): void
    {
        $this->fakeAi([
            ['meal_type_id' => $this->mealType['breakfast'], 'dish_id' => $this->dish['omelette']],
        ]);

        $this->generate();

        // Грамівки рахує buildPlanFromPersonal під калораж, а не модель.
        $this->assertNull(OrderDayDish::first()->weight_grams);
    }

    public function test_regenerating_replaces_the_previous_pick(): void
    {
        // Послідовність, а не два виклики fake(): повторний fake() не замінює
        // попередній, і модель віддавала б ту саму відповідь двічі.
        Http::fake(['api.openai.com/*' => Http::sequence()
            ->push($this->aiBody([['meal_type_id' => $this->mealType['lunch'], 'dish_id' => $this->dish['soup']]]))
            ->push($this->aiBody([['meal_type_id' => $this->mealType['lunch'], 'dish_id' => $this->dish['fish']]]))]);

        $this->generate();
        $this->generate();

        $this->assertSame(1, OrderDayDish::where('meal_type_id', $this->mealType['lunch'])->count());
        $this->assertSame($this->dish['fish'], OrderDayDish::first()->dish_id);
    }

    // --- захист від вигаданого --------------------------------------------

    public function test_it_throws_away_a_dish_that_does_not_exist(): void
    {
        $this->fakeAi([
            ['meal_type_id' => $this->mealType['breakfast'], 'dish_id' => 999999],
            ['meal_type_id' => $this->mealType['lunch'],     'dish_id' => $this->dish['soup']],
        ]);

        $result = $this->generate();

        // Приготувати неіснуючу страву неможливо — прийом лишається порожнім,
        // і менеджер бачить, який саме.
        $this->assertSame(1, $result['assigned']);
        $this->assertContains('Сніданок', $result['skipped']);
        $this->assertSame(1, OrderDayDish::count());
    }

    public function test_a_semi_finished_dish_is_not_offered(): void
    {
        $this->fakeAi([
            ['meal_type_id' => $this->mealType['breakfast'], 'dish_id' => $this->dish['stock']],
        ]);

        $result = $this->generate();

        // Напівфабрикат — заготовка, а не страва.
        $this->assertSame(0, $result['assigned']);
        $this->assertSame(0, OrderDayDish::count());
    }

    public function test_an_unrequested_meal_is_ignored(): void
    {
        $extra = $this->makeMealType('Перекус', 2);

        $this->fakeAi([
            ['meal_type_id' => $extra,                       'dish_id' => $this->dish['soup']],
            ['meal_type_id' => $this->mealType['breakfast'], 'dish_id' => $this->dish['omelette']],
        ]);

        $result = $this->generate();

        // Клієнт цього прийому не отримує — виробництво його не рахує.
        $this->assertSame(1, $result['assigned']);
        $this->assertSame(0, OrderDayDish::where('meal_type_id', $extra)->count());
    }

    // --- відмови -----------------------------------------------------------

    public function test_it_refuses_without_a_brief(): void
    {
        $this->client->update(['menu_brief' => null]);
        // Замовлення тримає клієнта у завантаженому звязку — інакше перевірка
        // побачить старий бриф.
        $this->order->unsetRelation('client');
        Http::fake();

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessageMatches('/бриф/u');

        $this->generate();

        Http::assertNothingSent();
    }

    public function test_it_refuses_without_an_api_key(): void
    {
        config()->set('services.openai.key', '');
        Http::fake();

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessageMatches('/OPENAI_API_KEY/');

        $this->generate();
    }

    public function test_it_reports_an_api_failure(): void
    {
        Http::fake(['api.openai.com/*' => Http::response('rate limited', 429)]);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessageMatches('/OpenAI/');

        $this->generate();
    }

    public function test_it_reports_a_broken_answer(): void
    {
        Http::fake([
            'api.openai.com/*' => Http::response([
                'choices' => [['message' => ['content' => 'не json']]],
            ]),
        ]);

        $this->expectException(ValidationException::class);

        $this->generate();
    }

    // --- що саме питаємо у моделі -----------------------------------------

    public function test_the_prompt_shows_what_each_dish_is_made_of(): void
    {
        $this->fakeAi([['meal_type_id' => $this->mealType['breakfast'], 'dish_id' => $this->dish['omelette']]]);

        $this->generate();

        Http::assertSent(function ($request) {
            $prompt = $request['messages'][1]['content'];

            // Клієнт у брифі пише про продукти, а не про назви страв —
            // без складу модель не зрозуміє, що суп це курка.
            return str_contains($prompt, 'Куряче філе')
                && str_contains($prompt, 'Буряк')
                // Дрібниця до 20 г промпт не роздуває.
                && ! str_contains($prompt, 'Сіль');
        });
    }

    public function test_ingredients_already_in_the_kitchen_are_starred(): void
    {
        // Стандартне меню на цю дату містить курку — отже вона вже в роботі.
        $menuId = DB::table('daily_menus')->insertGetId(['menu_plan_id' => 1, 'day_number' => 1]);
        DB::table('daily_menu_dishes')->insert([
            'daily_menu_id' => $menuId,
            'dish_id'       => $this->dish['soup'],
            'meal_type_id'  => $this->mealType['lunch'],
        ]);
        $this->fakeAi([['meal_type_id' => $this->mealType['breakfast'], 'dish_id' => $this->dish['omelette']]]);

        $this->generate();

        Http::assertSent(function ($request) {
            $prompt = $request['messages'][1]['content'];

            // Курка в роботі — із зірочкою; буряк ні.
            return str_contains($prompt, 'Куряче філе*')
                && str_contains($prompt, 'Буряк')
                && ! str_contains($prompt, 'Буряк*');
        });
    }

    public function test_the_brief_outranks_the_kitchen_in_the_instructions(): void
    {
        $this->fakeAi([['meal_type_id' => $this->mealType['breakfast'], 'dish_id' => $this->dish['omelette']]]);

        $this->generate();

        Http::assertSent(function ($request) {
            $system = $request['messages'][0]['content'];

            // Зручність кухні не має переважати побажання клієнта.
            return str_contains($system, 'НІКОЛИ не переважає');
        });
    }

    public function test_the_prompt_carries_the_brief_and_only_real_dishes(): void
    {
        $this->fakeAi([['meal_type_id' => $this->mealType['breakfast'], 'dish_id' => $this->dish['omelette']]]);

        $this->generate();

        Http::assertSent(function ($request) {
            $prompt = $request['messages'][1]['content'];

            return str_contains($prompt, 'Не їм: буряк, кефір')      // бриф передано як є
                && str_contains($prompt, 'Омлет з овочами')          // страва зі списку
                && str_contains($prompt, '1600 ккал')                // калораж замовлення
                && ! str_contains($prompt, 'Бульйон н/ф');           // напівфабрикат не пропонуємо
        });
    }
}
