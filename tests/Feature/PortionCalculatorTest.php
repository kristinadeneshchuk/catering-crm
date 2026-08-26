<?php

namespace Tests\Feature;

use App\Models\MealType;
use App\Models\PortionGrid;
use App\Models\Setting;
use App\Services\Portion\PortionCalculator;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Друга версія фасування: у страви рівно дві ваги.
 *
 * Стара версія ділить калораж між прийомами у відсотках, тож вага залежить від
 * калоражу — до двадцяти ваг на страву. Тут енергія бокса задана прямо, і вага
 * рахується один раз.
 *
 * Числа звірені з фасувальною матрицею замовника.
 */
class PortionCalculatorTest extends TestCase
{
    protected PortionCalculator $calc;
    protected MealType $breakfast;
    protected MealType $snack;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildSchema();

        $this->calc = app(PortionCalculator::class);

        $this->breakfast = MealType::create([
            'name' => 'Сніданок', 'sort_order' => 1, 'energy_percent' => 25,
            'box_kcal_std' => 400, 'box_kcal_large' => 600,
        ]);

        $this->snack = MealType::create([
            'name' => 'Перекус', 'sort_order' => 2, 'energy_percent' => 10,
            'box_kcal_std' => 200, 'box_kcal_large' => 400,
        ]);
    }

    protected function buildSchema(): void
    {
        Schema::create('settings', function (Blueprint $t) {
            $t->id(); $t->string('key')->unique(); $t->text('value')->nullable(); $t->timestamps();
        });

        Schema::create('meal_types', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->integer('sort_order')->default(1);
            $t->float('energy_percent')->default(0);
            $t->unsignedSmallInteger('box_kcal_std')->nullable();
            $t->unsignedSmallInteger('box_kcal_large')->nullable();
            $t->string('color')->nullable();
            $t->string('short_letter')->nullable();
            $t->timestamps();
        });

        Schema::create('dishes', function (Blueprint $t) {
            $t->id(); $t->string('name'); $t->float('base_weight_g')->nullable();
            $t->boolean('is_semi_finished')->default(false); $t->timestamps();
        });

        Schema::create('ingredients', function (Blueprint $t) {
            $t->id(); $t->string('name');
            $t->float('proteins_100g')->default(0);
            $t->float('fats_100g')->default(0);
            $t->float('carbs_100g')->default(0);
            $t->float('yield_percent')->default(100);
            $t->float('average_price')->default(0);
            $t->float('price_per_kg')->default(0);
            $t->string('unit')->default('кг');
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

        // Ingredient рахує середню ціну зі складських документів — таблиці
        // потрібні, хоча ціна для ваг не використовується.
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

        Schema::create('portion_grids', function (Blueprint $t) {
            $t->id();
            $t->unsignedSmallInteger('calories')->unique();
            $t->string('color')->nullable(); $t->string('color_label')->nullable();
            $t->unsignedTinyInteger('extra_snacks_std')->default(0);
            $t->unsignedTinyInteger('extra_snacks_large')->default(0);
            $t->boolean('is_active')->default(true);
            $t->text('comment')->nullable();
            $t->timestamps();
        });

        Schema::create('portion_grid_slots', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('portion_grid_id');
            $t->unsignedBigInteger('meal_type_id');
            $t->string('size', 8);
            $t->timestamps();
        });
    }

    /**
     * Страва із заданою калорійністю на 100 г.
     *
     * Калорійність у CRM не зберігається — вона рахується з БЖУ за формулою
     * 4-9-4. Тож задаємо потрібні ккал через вуглеводи: 1 г = 4 ккал.
     */
    protected function dish(string $name, float $kcalPer100g): \App\Models\Dish
    {
        $ing = DB::table('ingredients')->insertGetId([
            'name' => $name.' сировина', 'carbs_100g' => $kcalPer100g / 4,
        ]);

        $dish = \App\Models\Dish::create(['name' => $name, 'base_weight_g' => 100]);

        DB::table('dish_ingredients')->insert([
            'dish_id' => $dish->id, 'ingredient_id' => $ing, 'net_weight_g' => 100,
        ]);

        return $dish->fresh(['dishIngredients.ingredient']);
    }

    // --- ваги ---------------------------------------------------------------

    public function test_it_computes_both_weights_from_the_box_energy(): void
    {
        // 160 ккал/100 г: Std 400/160*100 = 250 г, L 600/160*100 = 375 г.
        $dish = $this->dish('Скрембл з пармезаном', 160);

        $both = $this->calc->bothPortions($dish, $this->breakfast);

        $this->assertSame(250, $both['std']['weight']);
        $this->assertSame(375, $both['large']['weight']);
    }

    public function test_a_light_dish_gets_a_bigger_portion(): void
    {
        // Чим легша страва, тим більша порція під ту саму енергію бокса.
        $light = $this->dish('Шакшука класична', 100);   // 400 г / 600 г
        $dense = $this->dish('Круасан з крем-сиром', 250); // 160 г / 240 г

        $this->assertSame(400, $this->calc->portion($light, $this->breakfast, false)['weight']);
        $this->assertSame(160, $this->calc->portion($dense, $this->breakfast, false)['weight']);
    }

    public function test_the_snack_box_is_half_the_breakfast(): void
    {
        // Сирники 129 ккал/100 г: снек Std 200 ккал → 155 г, L 400 → 310 г.
        $dish = $this->dish('Сирники з полуничним соусом', 129);

        $this->assertSame(155, $this->calc->portion($dish, $this->snack, false)['weight']);
        $this->assertSame(310, $this->calc->portion($dish, $this->snack, true)['weight']);
    }

    public function test_weight_is_rounded_to_the_configured_step(): void
    {
        $dish = $this->dish('Тестова', 136); // 400/136*100 = 294.1

        $this->assertSame(295, $this->calc->portion($dish, $this->breakfast, false)['weight']);

        // Крок налаштовується в CRM, а не в коді.
        Setting::create(['key' => PortionCalculator::KEY_ROUNDING, 'value' => '10']);

        $this->assertSame(290, $this->calc->portion($dish, $this->breakfast, false)['weight']);
    }

    // --- допуск -------------------------------------------------------------

    public function test_tolerance_is_a_percent_of_the_weight(): void
    {
        // 3% від 240 = 7.2 → 7
        $this->assertSame(7, $this->calc->tolerance(240));
        $this->assertSame(13, $this->calc->tolerance(440));
    }

    public function test_small_boxes_keep_a_minimum_tolerance(): void
    {
        // 3% від 80 г це два грами — такої точності станція не витримає.
        $this->assertSame(5, $this->calc->tolerance(80));
    }

    public function test_tolerance_is_configurable(): void
    {
        Setting::create(['key' => PortionCalculator::KEY_TOLERANCE, 'value' => '5']);

        $this->assertSame(12, $this->calc->tolerance(240));
    }

    // --- порожні дані -------------------------------------------------------

    public function test_a_meal_without_box_size_gives_nothing(): void
    {
        $lunch = MealType::create(['name' => 'Обід', 'sort_order' => 3, 'energy_percent' => 35]);
        $dish  = $this->dish('Будь-яка', 150);

        // Прийом не бере участі у другій версії — мовчки нуль не вигадуємо.
        $this->assertNull($this->calc->portion($dish, $lunch, false));
    }

    public function test_a_dish_without_calories_gives_nothing(): void
    {
        $dish = \App\Models\Dish::create(['name' => 'Порожня', 'base_weight_g' => 100]);

        $this->assertNull($this->calc->portion($dish->fresh(), $this->breakfast, false));
    }

    // --- сітка тарифів ------------------------------------------------------

    public function test_a_grid_that_adds_up_is_balanced(): void
    {
        $grid = PortionGrid::create(['calories' => 600]);
        $grid->slots()->create(['meal_type_id' => $this->breakfast->id, 'size' => PortionGrid::SIZE_STD]);
        $grid->slots()->create(['meal_type_id' => $this->snack->id, 'size' => PortionGrid::SIZE_STD]);

        // 400 + 200 = 600
        $this->assertTrue($grid->fresh(['slots.mealType'])->isBalanced());
    }

    public function test_a_grid_that_does_not_add_up_is_caught(): void
    {
        $grid = PortionGrid::create(['calories' => 1000]);
        $grid->slots()->create(['meal_type_id' => $this->breakfast->id, 'size' => PortionGrid::SIZE_STD]);

        $fresh = $grid->fresh(['slots.mealType']);

        // 400 замість 1000 — краще побачити тут, ніж у виробництві.
        $this->assertFalse($fresh->isBalanced());
        $this->assertSame(400, $fresh->actualKcal());
    }

    public function test_extra_snacks_count_towards_the_tariff(): void
    {
        $grid = PortionGrid::create(['calories' => 800, 'extra_snacks_std' => 1]);
        $grid->slots()->create(['meal_type_id' => $this->breakfast->id, 'size' => PortionGrid::SIZE_STD]);
        $grid->slots()->create(['meal_type_id' => $this->snack->id, 'size' => PortionGrid::SIZE_STD]);

        // 400 + 200 + додатковий снек 200 = 800
        $this->assertTrue($grid->fresh(['slots.mealType'])->isBalanced());
    }

    public function test_the_real_2400_tariff_adds_up(): void
    {
        $lunch  = MealType::create(['name' => 'Обід', 'sort_order' => 3, 'energy_percent' => 35, 'box_kcal_std' => 600, 'box_kcal_large' => 800]);
        $after  = MealType::create(['name' => 'Полуденок', 'sort_order' => 4, 'energy_percent' => 10, 'box_kcal_std' => 200, 'box_kcal_large' => 400]);
        $dinner = MealType::create(['name' => 'Вечеря', 'sort_order' => 5, 'energy_percent' => 20, 'box_kcal_std' => 400, 'box_kcal_large' => 600]);

        $grid = PortionGrid::create(['calories' => 2400]);
        $grid->slots()->create(['meal_type_id' => $this->breakfast->id, 'size' => PortionGrid::SIZE_LARGE]); // 600
        $grid->slots()->create(['meal_type_id' => $this->snack->id,     'size' => PortionGrid::SIZE_STD]);   // 200
        $grid->slots()->create(['meal_type_id' => $lunch->id,           'size' => PortionGrid::SIZE_LARGE]); // 800
        $grid->slots()->create(['meal_type_id' => $after->id,           'size' => PortionGrid::SIZE_STD]);   // 200
        $grid->slots()->create(['meal_type_id' => $dinner->id,          'size' => PortionGrid::SIZE_LARGE]); // 600

        $this->assertSame(2400, $grid->fresh(['slots.mealType'])->actualKcal());
    }
}
