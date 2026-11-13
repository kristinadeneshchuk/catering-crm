<?php

namespace Tests\Feature;

use App\Models\Dish;
use App\Models\Ingredient;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Міст до Lunch Hub.
 *
 * Головне, що тут перевіряється, — не форма відповіді, а те, що Lunch Hub
 * порахує собівартість обіду так само, як її рахує CRM. Тому собівартість і
 * КБЖУ беруться з Dish::$calculated_totals, а не рахуються тут заново.
 */
class LunchApiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->buildSchema();

        config()->set('services.lunch.token', 'test-lunch-token');
    }

    protected function buildSchema(): void
    {
        Schema::create('dishes', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->text('description')->nullable();
            $t->float('base_weight_g')->nullable();
            $t->string('packaging_type')->nullable();
            $t->float('stock')->default(0);
            $t->string('group')->nullable();
            $t->string('photo')->nullable();
            $t->boolean('is_semi_finished')->default(false);
            $t->string('ext_id')->nullable();
            $t->timestamps();
        });

        Schema::create('ingredients', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('unit')->default('кг');
            $t->boolean('is_packaged')->default(false);
            $t->float('package_weight')->nullable();
            $t->string('package_unit')->nullable();
            $t->float('price_per_kg')->default(0);
            $t->float('calories_100g')->default(0);
            $t->float('proteins_100g')->default(0);
            $t->float('fats_100g')->default(0);
            $t->float('carbs_100g')->default(0);
            $t->float('yield_percent')->default(100);
            $t->float('stock')->default(0);
            $t->string('group')->nullable();
            $t->string('photo')->nullable();
            $t->string('ext_id')->nullable();
            $t->timestamps();
        });

        Schema::create('dish_ingredients', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('dish_id');
            $t->unsignedBigInteger('ingredient_id')->nullable();
            $t->unsignedBigInteger('child_dish_id')->nullable();
            $t->string('type')->default('product');
            $t->float('net_weight_g')->default(0);
        });

        // Середня ціна рахується зі складських документів.
        Schema::create('stock_documents', function (Blueprint $t) {
            $t->id(); $t->string('type')->nullable(); $t->string('status')->default('completed'); $t->timestamps();
        });

        Schema::create('menu_plans', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->text('description')->nullable();
            $t->unsignedInteger('cycle_days')->default(28);
            $t->date('cycle_start_date')->nullable();
            $t->boolean('is_default')->default(false);
            $t->unsignedInteger('sort_order')->default(0);
            $t->timestamps();
        });

        Schema::create('daily_menus', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('menu_plan_id')->nullable();
            $t->unsignedInteger('day_number')->nullable();
            $t->unsignedInteger('target_kcal')->default(1500);
            $t->unsignedInteger('target_protein_g')->default(113);
            $t->unsignedInteger('target_fat_g')->default(50);
            $t->unsignedInteger('target_carb_g')->default(150);
            $t->timestamps();
        });

        Schema::create('daily_menu_dishes', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('daily_menu_id');
            $t->unsignedBigInteger('dish_id');
            $t->unsignedBigInteger('meal_type_id')->nullable();
            $t->float('custom_energy_percent')->nullable();
        });

        Schema::create('meal_types', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->unsignedInteger('sort_order')->default(0);
            $t->float('energy_percent')->default(0);
            $t->timestamps();
        });

        Schema::create('lunch_orders', function (Blueprint $t) {
            $t->id();
            $t->date('date');
            $t->unsignedBigInteger('company_id');
            $t->string('company_name');
            $t->unsignedBigInteger('client_id')->nullable();
            $t->unsignedInteger('sets')->default(0);
            $t->decimal('amount', 10, 2)->default(0);
            $t->string('address')->nullable();
            $t->string('delivery_window')->nullable();
            $t->string('contact')->nullable();
            $t->text('comment')->nullable();
            $t->json('people')->nullable();
            $t->timestamp('received_at')->nullable();
            $t->timestamps();
            $t->unique(['date', 'company_id']);
        });

        Schema::create('lunch_order_lines', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('lunch_order_id');
            $t->unsignedBigInteger('dish_id')->nullable();
            $t->string('name');
            $t->string('kind', 20)->nullable();
            $t->unsignedInteger('qty')->default(0);
            $t->unsignedInteger('weight_g')->nullable();
            $t->timestamps();
        });

        if (! Schema::hasTable('clients')) {
            Schema::create('clients', fn (Blueprint $t) => $t->id());
        }

        Schema::create('lunch_company_links', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id')->unique();
            $t->string('company_name')->nullable();
            $t->unsignedBigInteger('client_id');
            $t->unsignedBigInteger('user_id')->nullable();
            $t->timestamps();
        });

        Schema::create('stock_document_items', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('stock_document_id')->nullable();
            $t->unsignedBigInteger('itemable_id')->nullable();
            $t->string('itemable_type')->nullable();
            $t->float('qty')->default(0);
            $t->float('price')->default(0);
            $t->timestamps();
        });
    }

    private function ask(string $url, ?string $token = 'test-lunch-token')
    {
        return $this->getJson($url, $token ? ['Authorization' => "Bearer {$token}"] : []);
    }

    /** Страва з одного інгредієнта: 1000 г нетто по 200 грн/кг. */
    private function makeDish(array $attrs = [], float $pricePerKg = 200, float $yield = 100): Dish
    {
        $ing = Ingredient::create([
            'name' => 'філе куряче', 'unit' => 'кг',
            'price_per_kg' => $pricePerKg, 'yield_percent' => $yield,
            'calories_100g' => 113, 'proteins_100g' => 23, 'fats_100g' => 1, 'carbs_100g' => 0,
        ]);

        $dish = Dish::create(array_merge([
            'name' => 'Плов класичний', 'group' => 'Обід', 'base_weight_g' => 1000,
            'is_semi_finished' => false,
        ], $attrs));

        DB::table('dish_ingredients')->insert([
            'dish_id' => $dish->id, 'ingredient_id' => $ing->id,
            'type' => 'product', 'net_weight_g' => 1000,
        ]);

        return $dish;
    }

    // --- доступ ---------------------------------------------------------------

    public function test_without_a_token_nothing_is_served(): void
    {
        $this->ask('/api/lunch/dishes', null)->assertStatus(401);
    }

    public function test_a_wrong_token_is_rejected(): void
    {
        $this->ask('/api/lunch/dishes', 'not-the-token')->assertStatus(401);
    }

    public function test_endpoints_are_closed_where_lunch_is_not_configured(): void
    {
        // Репозиторій деплоїться на три сервери. Там, де Lunch Hub не
        // підключений, ендпоінти мають бути закриті, а не чекати ключ.
        config()->set('services.lunch.token', '');

        $this->ask('/api/lunch/dishes', 'anything')->assertStatus(503);
    }

    // --- каталог страв --------------------------------------------------------

    public function test_the_catalog_gives_cost_and_nutrition_from_the_tech_card(): void
    {
        $dish = $this->makeDish();

        $response = $this->ask('/api/lunch/dishes')->assertOk();

        $row = $response->json('data.0');

        $this->assertSame($dish->id, $row['id']);
        $this->assertSame('Плов класичний', $row['name']);
        $this->assertSame('Обід', $row['group']);

        // 1000 г по 200 грн/кг = 200 грн за вихід техкартки.
        $this->assertEquals(200.0, $row['cost']);
        $this->assertEquals(1000.0, $row['output_weight_g']);

        // КБЖУ рахується з БЖУ інгредієнта, а не береться з calories_100g.
        $this->assertEquals(230.0, $row['protein_g']);
        $this->assertEquals(10.0, $row['fat_g']);
        $this->assertEquals(0.0, $row['carb_g']);
        $this->assertEquals(1010.0, $row['kcal']); // 230*4 + 10*9
    }

    public function test_the_cost_accounts_for_cleaning_loss(): void
    {
        // Вихід 80%: щоб покласти 1000 г у тарілку, купити треба 1250 г.
        // Якщо Lunch Hub рахуватиме без цього, обіди будуть дешевші на папері.
        $this->makeDish([], 200, 80);

        $this->assertEquals(250.0, $this->ask('/api/lunch/dishes')->json('data.0.cost'));
    }

    public function test_semi_finished_dishes_stay_out_of_the_catalog(): void
    {
        // Соуси й бульйони як окремий обід не замовляють.
        $this->makeDish(['name' => 'Готова страва']);
        $this->makeDish(['name' => 'Соус демі-глас', 'is_semi_finished' => true]);

        $data = $this->ask('/api/lunch/dishes')->json('data');

        $this->assertCount(1, $data);
        $this->assertSame('Готова страва', $data[0]['name']);
    }

    public function test_a_dish_without_a_photo_gives_null_not_a_broken_url(): void
    {
        $this->makeDish();

        $this->assertNull($this->ask('/api/lunch/dishes')->json('data.0.photo_url'));
    }

    // --- інгредієнти ----------------------------------------------------------

    public function test_ingredients_carry_the_price_crm_actually_costs_with(): void
    {
        // Ручна ціна 198, але надходження були по 220 — рахуємо по надходженнях,
        // інакше обіди рахувались би за торішніми цінами.
        $ing = Ingredient::create([
            'name' => 'філе куряче', 'unit' => 'кг', 'price_per_kg' => 198,
            'calories_100g' => 113, 'proteins_100g' => 23, 'fats_100g' => 1, 'carbs_100g' => 0,
        ]);

        $doc = DB::table('stock_documents')->insertGetId(['type' => 'receipt']);
        DB::table('stock_document_items')->insert([
            'stock_document_id' => $doc, 'itemable_id' => $ing->id,
            'itemable_type' => Ingredient::class, 'qty' => 10, 'price' => 220,
        ]);

        $row = $this->ask('/api/lunch/ingredients')->assertOk()->json('data.0');

        $this->assertEquals(220.0, $row['price_per_kg']);
        $this->assertEquals(113.0, $row['kcal_100g']);
        $this->assertEquals(23.0, $row['protein_100g']);
    }

    public function test_ingredients_without_receipts_fall_back_to_the_manual_price(): void
    {
        Ingredient::create(['name' => 'сіль', 'unit' => 'кг', 'price_per_kg' => 15]);

        $this->assertEquals(15.0, $this->ask('/api/lunch/ingredients')->json('data.0.price_per_kg'));
    }

    public function test_the_unit_is_exposed_so_pieces_are_not_read_as_kilograms(): void
    {
        // У двох десятків позицій одиниця — «шт», і тоді price_per_kg це ціна за
        // штуку. Без цього поля Lunch Hub помилився б у рази.
        Ingredient::create(['name' => 'лаваш', 'unit' => 'шт', 'price_per_kg' => 25]);

        $row = $this->ask('/api/lunch/ingredients')->json('data.0');

        $this->assertSame('шт', $row['unit']);
        $this->assertEquals(25.0, $row['price_per_kg']);
    }

    // --- зведення на кухню ----------------------------------------------------

    public function test_a_kitchen_plan_is_stored_as_a_file(): void
    {
        Storage::fake('lunch');

        $response = $this->postJson('/api/lunch/kitchen-plan', [
            'date'  => '2026-09-10',
            'lines' => [
                ['dish_id' => 536, 'name' => 'Плов класичний', 'qty' => 12],
                ['dish_id' => 537, 'name' => 'Борщ', 'qty' => 8],
            ],
        ], ['Authorization' => 'Bearer test-lunch-token']);

        $response->assertOk()->assertJson(['ok' => true, 'lines' => 2]);

        Storage::disk('lunch')->assertExists('kitchen-plan-2026-09-10.json');

        $saved = json_decode(Storage::disk('lunch')->get('kitchen-plan-2026-09-10.json'), true);

        $this->assertSame('2026-09-10', $saved['date']);
        $this->assertEquals(20, $saved['total_qty']);
        $this->assertSame('Плов класичний', $saved['lines'][0]['name']);
    }

    public function test_resending_the_same_day_replaces_the_plan(): void
    {
        Storage::fake('lunch');

        $send = fn (int $qty) => $this->postJson('/api/lunch/kitchen-plan', [
            'date'  => '2026-09-10',
            'lines' => [['dish_id' => 536, 'name' => 'Плов', 'qty' => $qty]],
        ], ['Authorization' => 'Bearer test-lunch-token']);

        $send(12);
        $send(20);

        // Логіст перебудував план і надіслав ще раз. Два зведення на один день
        // кухні тільки заважали б.
        $saved = json_decode(Storage::disk('lunch')->get('kitchen-plan-2026-09-10.json'), true);

        $this->assertEquals(20, $saved['total_qty']);
    }

    public function test_a_plan_without_lines_is_refused(): void
    {
        $this->postJson('/api/lunch/kitchen-plan', ['date' => '2026-09-10', 'lines' => []],
            ['Authorization' => 'Bearer test-lunch-token'])
            ->assertStatus(422);
    }

    // --- обіди по компаніях ------------------------------------------------------

    private function sendOrders(array $companies, string $date = '2026-11-13')
    {
        return $this->postJson('/api/lunch/orders', ['date' => $date, 'companies' => $companies],
            ['Authorization' => 'Bearer test-lunch-token']);
    }

    private function company(int $id, int $sets, ?array $lines = null): array
    {
        return [
            'id' => $id, 'name' => "Компанія {$id}", 'sets' => $sets, 'amount' => $sets * 350,
            'address' => 'вул. Тестова, 1', 'delivery_window' => '12:00–13:00',
            'lines' => $lines ?? [
                ['dish_id' => 450, 'name' => 'Курка з рисом', 'kind' => 'main', 'qty' => $sets, 'weight_g' => 320],
                ['dish_id' => 448, 'name' => 'Млинці', 'kind' => 'light', 'qty' => $sets, 'weight_g' => 200],
            ],
        ];
    }

    public function test_orders_are_stored_per_company(): void
    {
        $this->sendOrders([$this->company(1, 12), $this->company(2, 5)])
            ->assertOk()->assertJson(['ok' => true, 'companies' => 2, 'sets' => 17]);

        $this->assertSame(2, \App\Models\LunchOrder::count());
        $first = \App\Models\LunchOrder::where('company_id', 1)->first();
        $this->assertSame('2026-11-13', $first->date->format('Y-m-d'));
        $this->assertEquals(12, $first->sets);
        $this->assertEquals(4200, $first->amount);
        $this->assertSame(2, $first->lines()->count());
        $this->assertEquals(320, $first->lines()->where('kind', 'main')->first()->weight_g);
    }

    public function test_resending_a_day_replaces_it_and_drops_cancelled_companies(): void
    {
        $this->sendOrders([$this->company(1, 12), $this->company(2, 5)])->assertOk();
        $this->sendOrders([$this->company(1, 9)])->assertOk()->assertJson(['sets' => 9]);

        $this->assertSame(1, \App\Models\LunchOrder::count());
        $this->assertEquals(9, \App\Models\LunchOrder::first()->sets);
        $this->assertSame(2, \App\Models\LunchOrderLine::count());
    }

    public function test_resending_one_day_leaves_other_days_alone(): void
    {
        $this->sendOrders([$this->company(1, 12)], '2026-11-13')->assertOk();
        $this->sendOrders([$this->company(1, 7)], '2026-11-14')->assertOk();
        $this->sendOrders([], '2026-11-14')->assertOk()->assertJson(['companies' => 0, 'sets' => 0]);

        $this->assertSame(1, \App\Models\LunchOrder::count());
        $this->assertSame('2026-11-13', \App\Models\LunchOrder::first()->date->format('Y-m-d'));
    }

    public function test_orders_with_a_broken_company_are_refused_whole(): void
    {
        $this->sendOrders([$this->company(1, 12)])->assertOk();

        $this->sendOrders([$this->company(1, 3), $this->company(2, 5, [])])->assertStatus(422);

        // Попередній знімок лишився як був.
        $this->assertSame(1, \App\Models\LunchOrder::count());
        $this->assertEquals(12, \App\Models\LunchOrder::first()->sets);
    }

    public function test_people_are_kept_for_name_labels(): void
    {
        $company = $this->company(1, 1) + ['people' => [
            ['name' => 'Олена К.', 'lines' => [
                ['dish_id' => 450, 'kind' => 'main', 'weight_g' => 320],
                ['dish_id' => 448, 'kind' => 'light', 'weight_g' => 200],
            ]],
        ]];

        $this->sendOrders([$company])->assertOk();

        $people = \App\Models\LunchOrder::first()->people;
        $this->assertSame('Олена К.', $people[0]['name']);
        $this->assertSame(450, $people[0]['lines'][0]['dish_id']);
        $this->assertNull(\App\Models\LunchOrder::first()->fresh()->comment);
    }

    public function test_company_link_survives_new_snapshots(): void
    {
        $this->sendOrders([$this->company(12, 14), $this->company(13, 6)])->assertOk();
        $this->assertNull(\App\Models\LunchOrder::where('company_id', 12)->value('client_id'));

        \App\Models\LunchCompanyLink::link(12, 'ТЕСТ від ХП', 501);
        $this->assertSame(501, (int) \App\Models\LunchOrder::where('company_id', 12)->value('client_id'));
        $this->assertNull(\App\Models\LunchOrder::where('company_id', 13)->value('client_id'));

        // Знімок перезаписали і прислали наступний день — привʼязка на місці.
        $this->sendOrders([$this->company(12, 9)])->assertOk();
        $this->sendOrders([$this->company(12, 3)], '2026-11-14')->assertOk();
        $this->assertSame([501, 501], \App\Models\LunchOrder::where('company_id', 12)->orderBy('date')->pluck('client_id')->map(fn ($v) => (int) $v)->all());

        \App\Models\LunchCompanyLink::unlink(12);
        $this->assertSame(0, \App\Models\LunchOrder::whereNotNull('client_id')->count());
    }

    public function test_crm_link_wins_over_client_id_from_lunch_hub(): void
    {
        \App\Models\LunchCompanyLink::link(12, 'ТЕСТ від ХП', 501);

        $this->sendOrders([['crm_client_id' => 777] + $this->company(12, 4), ['crm_client_id' => 888] + $this->company(13, 2)])->assertOk();

        $this->assertSame(501, (int) \App\Models\LunchOrder::where('company_id', 12)->value('client_id'));
        $this->assertSame(888, (int) \App\Models\LunchOrder::where('company_id', 13)->value('client_id'));
    }

    public function test_orders_need_a_token(): void
    {
        $this->postJson('/api/lunch/orders', ['date' => '2026-11-13', 'companies' => []])->assertStatus(401);
        $this->assertSame(0, \App\Models\LunchOrder::count());
    }

    public function test_a_plan_without_a_token_is_refused(): void
    {
        $this->postJson('/api/lunch/kitchen-plan', [
            'date' => '2026-09-10',
            'lines' => [['dish_id' => 1, 'qty' => 1]],
        ])->assertStatus(401);
    }

    // --- цикл раціонів --------------------------------------------------------

    /** План на 28 днів зі стартом 25.03.2026 — як на проді. */
    private function makeCycle(): array
    {
        \App\Models\MenuPlan::forgetDefault();

        $plan = \App\Models\MenuPlan::create([
            'name' => 'Стандарт', 'cycle_days' => 28,
            'cycle_start_date' => '2026-03-25', 'is_default' => true,
        ]);

        $breakfast = \App\Models\MealType::create(['name' => 'Сніданок', 'sort_order' => 1]);
        $snack     = \App\Models\MealType::create(['name' => 'Перекус 1', 'sort_order' => 2]);
        $lunch     = \App\Models\MealType::create(['name' => 'Обід', 'sort_order' => 3]);

        $menu = \App\Models\DailyMenu::create(['menu_plan_id' => $plan->id, 'day_number' => 23]);

        // Порядок рядків навмисно не збігається з порядком прийомів.
        foreach ([[$lunch, 'Плов'], [$breakfast, 'Омлет'], [$snack, 'Чіа пудинг']] as [$type, $name]) {
            \App\Models\DailyMenuDish::create([
                'daily_menu_id' => $menu->id,
                'dish_id'       => $this->makeDish(['name' => $name])->id,
                'meal_type_id'  => $type->id,
            ]);
        }

        return [$plan, $menu];
    }

    public function test_the_cycle_menu_gives_the_day_that_falls_on_the_date(): void
    {
        $this->makeCycle();

        // 01.10.2026 — 23-й день циклу від 25.03.2026.
        $response = $this->ask('/api/lunch/cycle-menu?date=2026-10-01')->assertOk();

        $this->assertSame(23, $response->json('day_number'));
        $this->assertSame(28, $response->json('plan.cycle_days'));

        // Прийоми йдуть у порядку довідника, а не в порядку додавання страв:
        // Lunch Hub показує їх списком зверху вниз.
        $this->assertSame(
            ['Сніданок', 'Перекус 1', 'Обід'],
            array_column($response->json('items'), 'meal_type'),
        );
        $this->assertSame(['Омлет', 'Чіа пудинг', 'Плов'], array_column($response->json('items'), 'dish_name'));
        $this->assertSame([1, 2, 3], array_column($response->json('items'), 'meal_sort'));
    }

    public function test_an_unfilled_cycle_day_answers_404_not_an_empty_menu(): void
    {
        $this->makeCycle();

        // Наступна дата — 24-й день, його не заповнювали. Порожній список
        // Lunch Hub показав би як меню без страв, тож тут саме 404.
        $this->ask('/api/lunch/cycle-menu?date=2026-10-02')
            ->assertStatus(404)
            ->assertJsonPath('day_number', 24);
    }

    public function test_the_cycle_menu_needs_a_token_too(): void
    {
        $this->ask('/api/lunch/cycle-menu?date=2026-10-01', null)->assertStatus(401);
    }

    // --- продукти в грамах ---------------------------------------------------

    /** Страва зі 100 г продукту з одиницею «г». */
    private function gramDish(float $pricePerKg, ?array $receipt = null): Dish
    {
        Ingredient::clearAveragePriceCache();

        $ing = Ingredient::create(['name' => 'рис для суші', 'unit' => 'г', 'price_per_kg' => $pricePerKg, 'yield_percent' => 100]);

        if ($receipt) {
            $doc = DB::table('stock_documents')->insertGetId(['type' => 'receipt', 'status' => 'completed']);
            DB::table('stock_document_items')->insert([
                'stock_document_id' => $doc, 'itemable_id' => $ing->id,
                'itemable_type' => Ingredient::class, 'qty' => $receipt[0], 'price' => $receipt[1],
            ]);
        }

        $dish = Dish::create(['name' => 'Онігірі', 'group' => 'Обід', 'base_weight_g' => 100, 'is_semi_finished' => false]);
        DB::table('dish_ingredients')->insert([
            'dish_id' => $dish->id, 'ingredient_id' => $ing->id, 'type' => 'product', 'net_weight_g' => 100,
        ]);

        return $dish;
    }

    public function test_a_gram_unit_price_is_still_per_kilogram(): void
    {
        // Склад читає price_per_kg як ціну за кг і для «г». Собівартість брала
        // її за грам — і 100 г рису по 117 грн/кг коштували 11 700 грн.
        $this->gramDish(117);

        $this->assertEquals(11.7, $this->ask('/api/lunch/dishes')->json('data.0.cost'));
        $this->assertEquals(117.0, $this->ask('/api/lunch/ingredients')->json('data.0.price_per_kg'));
    }

    public function test_a_receipt_entered_in_grams_is_read_per_gram(): void
    {
        // Прошуто: прихід 500 г по 1,12 грн за грам.
        $this->gramDish(1120, [500, 1.12]);

        $this->assertEquals(112.0, $this->ask('/api/lunch/dishes')->json('data.0.cost'));
    }

    public function test_a_receipt_entered_in_kilograms_is_read_per_kilogram(): void
    {
        // Рис: той самий продукт у грамах, але прихід внесли як 2 кг по 145.
        $this->gramDish(117, [2, 145]);

        $this->assertEquals(14.5, $this->ask('/api/lunch/dishes')->json('data.0.cost'));
    }
}
