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
            $t->id(); $t->string('type')->nullable(); $t->timestamps();
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
        Storage::fake('local');

        $response = $this->postJson('/api/lunch/kitchen-plan', [
            'date'  => '2026-09-10',
            'lines' => [
                ['dish_id' => 536, 'name' => 'Плов класичний', 'qty' => 12],
                ['dish_id' => 537, 'name' => 'Борщ', 'qty' => 8],
            ],
        ], ['Authorization' => 'Bearer test-lunch-token']);

        $response->assertOk()->assertJson(['ok' => true, 'lines' => 2]);

        Storage::disk('local')->assertExists('lunch/kitchen-plan-2026-09-10.json');

        $saved = json_decode(Storage::disk('local')->get('lunch/kitchen-plan-2026-09-10.json'), true);

        $this->assertSame('2026-09-10', $saved['date']);
        $this->assertEquals(20, $saved['total_qty']);
        $this->assertSame('Плов класичний', $saved['lines'][0]['name']);
    }

    public function test_resending_the_same_day_replaces_the_plan(): void
    {
        Storage::fake('local');

        $send = fn (int $qty) => $this->postJson('/api/lunch/kitchen-plan', [
            'date'  => '2026-09-10',
            'lines' => [['dish_id' => 536, 'name' => 'Плов', 'qty' => $qty]],
        ], ['Authorization' => 'Bearer test-lunch-token']);

        $send(12);
        $send(20);

        // Логіст перебудував план і надіслав ще раз. Два зведення на один день
        // кухні тільки заважали б.
        $saved = json_decode(Storage::disk('local')->get('lunch/kitchen-plan-2026-09-10.json'), true);

        $this->assertEquals(20, $saved['total_qty']);
    }

    public function test_a_plan_without_lines_is_refused(): void
    {
        $this->postJson('/api/lunch/kitchen-plan', ['date' => '2026-09-10', 'lines' => []],
            ['Authorization' => 'Bearer test-lunch-token'])
            ->assertStatus(422);
    }

    public function test_a_plan_without_a_token_is_refused(): void
    {
        $this->postJson('/api/lunch/kitchen-plan', [
            'date' => '2026-09-10',
            'lines' => [['dish_id' => 1, 'qty' => 1]],
        ])->assertStatus(401);
    }
}
