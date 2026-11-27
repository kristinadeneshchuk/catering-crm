<?php

namespace Tests\Feature;

use App\Models\DailyMenu;
use App\Models\DailyMenuDish;
use App\Models\Dish;
use App\Models\MealType;
use App\Support\PublicMenuBuilder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Лінійка меню на публічній сторінці: страва-заміна стає на місце страви циклу,
 * порція рахується за калорійністю заміни — калораж дня той самий.
 */
class PublicMenuLinesTest extends TestCase
{
    protected function dish(int $id, string $name, float $prot, float $fat, float $carb): Dish
    {
        $dish = new class extends Dish {
            public array $totals = [];

            public function getCalculatedTotalsAttribute(): array
            {
                return $this->totals;
            }
        };
        $dish->forceFill(['id' => $id, 'name' => $name, 'base_weight_g' => 100]);
        $dish->totals = ['kcal' => $prot * 4 + $fat * 9 + $carb * 4, 'prot' => $prot, 'fat' => $fat, 'carb' => $carb, 'cost' => 0, 'output_weight' => 100];

        return $dish;
    }

    protected function menu(Dish $dish): DailyMenu
    {
        Schema::create('meal_plans', function (Blueprint $t) {
            $t->id();
            $t->string('name')->nullable();
            $t->integer('min_kcal')->default(0);
            $t->integer('max_kcal')->default(0);
            $t->timestamps();
        });

        $meal = new MealType();
        $meal->forceFill(['id' => 9, 'name' => 'Обід', 'sort_order' => 3, 'energy_percent' => 100]);
        $item = new DailyMenuDish();
        $item->forceFill(['dish_id' => $dish->id, 'meal_type_id' => 9]);
        $item->setRelation('dish', $dish);
        $item->setRelation('mealType', $meal);

        $menu = new DailyMenu();
        $menu->setRelation('menuItems', collect([$item]));

        return $menu;
    }

    public function test_a_line_swaps_the_dish_and_keeps_the_day_calories(): void
    {
        $onigiri = $this->dish(826, 'Онігірі', 6, 5, 25);                          // 169 ккал/100 г
        $chicken = $this->dish(833, 'Інд · Курячий рулет із квасолею', 13, 1, 18); // 133 ккал/100 г
        $menu = $this->menu($onigiri);

        $standard = PublicMenuBuilder::build($menu, 2000);
        $sport    = PublicMenuBuilder::build($menu, 2000, null, [826 => $chicken]);

        $this->assertSame('Онігірі', $standard['items'][0]['dish_name']);
        $this->assertSame('Курячий рулет із квасолею', $sport['items'][0]['dish_name']); // без службового «Інд ·»
        $this->assertGreaterThan($standard['items'][0]['weight'], $sport['items'][0]['weight']);
        $this->assertEqualsWithDelta(2000, $sport['totals']['kcal'], 5);
        $this->assertGreaterThan($standard['totals']['prot'] * 2, $sport['totals']['prot']);
        $this->assertLessThan($standard['totals']['fat'] / 2, $sport['totals']['fat']);
    }

    public function test_a_swap_without_a_tech_card_leaves_the_menu_dish(): void
    {
        $onigiri = $this->dish(826, 'Онігірі', 6, 5, 25);
        $menu = $this->menu($onigiri);

        $built = PublicMenuBuilder::build($menu, 2000, null, [826 => $this->dish(1, 'Порожня', 0, 0, 0)]);

        $this->assertSame('Онігірі', $built['items'][0]['dish_name']);
    }

    public function test_the_page_shows_line_tabs_and_keeps_line_and_calories_in_links(): void
    {
        $sport = new \App\Models\ReplacementBundle();
        $sport->forceFill(['id' => 2, 'name' => 'Спорт']);
        $pro = new \App\Models\ReplacementBundle();
        $pro->forceFill(['id' => 3, 'name' => 'Спорт Про']);

        $html = view('menu.today', [
            'days' => [], 'kcal' => 2400, 'tiers' => [1800, 2400], 'lines' => collect([$sport, $pro]), 'line' => $pro,
        ])->render();

        $this->assertStringContainsString('>Стандарт</a>', $html);
        $this->assertStringContainsString('line=3&amp;kcal=2400">Спорт Про</a>', $html);
        $this->assertStringContainsString('line=3&amp;kcal=1800', $html);   // калораж не скидає лінійку
        $this->assertMatchesRegularExpression('/class="tier active"[^>]*>Спорт Про/', $html);

        $plain = view('menu.today', ['days' => [], 'kcal' => 1800, 'tiers' => [1800], 'lines' => collect(), 'line' => null])->render();
        $this->assertStringNotContainsString('>Стандарт</a>', $plain);          // без лінійок сторінка як була
    }
}
