<?php

namespace Tests\Feature;

use App\Models\LunchOrder;
use App\Models\LunchOrderLine;
use App\Services\Kitchen\LunchKitchen;
use Tests\TestCase;

class LunchKitchenTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        (require database_path('migrations/2026_11_11_100000_create_lunch_orders.php'))->up();

        $a = LunchOrder::create(['date' => '2026-11-20', 'company_id' => 1, 'company_name' => 'ТЕСТ від ХП', 'sets' => 14, 'amount' => 0]);
        $b = LunchOrder::create(['date' => '2026-11-20', 'company_id' => 2, 'company_name' => 'ТЕСТ від ХП 2', 'sets' => 6, 'amount' => 0]);
        $c = LunchOrder::create(['date' => '2026-11-21', 'company_id' => 1, 'company_name' => 'ТЕСТ від ХП', 'sets' => 50, 'amount' => 0]);

        LunchOrderLine::create(['lunch_order_id' => $a->id, 'dish_id' => 10, 'name' => 'Основна', 'kind' => 'main', 'qty' => 14, 'weight_g' => 300]);
        LunchOrderLine::create(['lunch_order_id' => $b->id, 'dish_id' => 10, 'name' => 'Основна', 'kind' => 'main', 'qty' => 6, 'weight_g' => 300]);
        LunchOrderLine::create(['lunch_order_id' => $a->id, 'dish_id' => 11, 'name' => 'Без ваги', 'kind' => 'light', 'qty' => 14, 'weight_g' => null]);
        LunchOrderLine::create(['lunch_order_id' => $a->id, 'dish_id' => null, 'name' => 'Без страви', 'kind' => 'light', 'qty' => 14, 'weight_g' => 200]);
        LunchOrderLine::create(['lunch_order_id' => $c->id, 'dish_id' => 10, 'name' => 'Основна', 'kind' => 'main', 'qty' => 50, 'weight_g' => 300]);
    }

    private function dish(int $id, int $base = 150): object
    {
        return (object) ['id' => $id, 'base_weight_g' => $base];
    }

    public function test_only_lines_of_the_day_with_dish_and_weight_count(): void
    {
        $lunch = LunchKitchen::forDate('2026-11-20');

        $this->assertSame([10], array_keys($lunch->all()));
        $this->assertSame(20, array_sum(array_column($lunch->all()[10], 'qty')));
        $this->assertTrue(LunchKitchen::forDate('2026-11-22')->isEmpty());
    }

    public function test_portions_go_to_the_common_pot_once(): void
    {
        $lunch = LunchKitchen::forDate('2026-11-20');
        $standard = [['order' => null, 'scale' => 1.0]];

        $this->assertSame(20, $lunch->pushStandard($standard, $this->dish(10)));
        $this->assertCount(21, $standard);
        $this->assertEquals(2.0, $standard[20]['scale']);
        // Та сама страва вдруге в меню дня — обіди вже враховано.
        $this->assertSame(0, $lunch->pushStandard($standard, $this->dish(10)));
        $this->assertCount(21, $standard);
    }

    public function test_packing_gets_a_lunch_column_split_by_company(): void
    {
        $lunch = LunchKitchen::forDate('2026-11-20');
        $table = ['columns' => [1400 => ['label' => '1400–1700', 'count' => 5, 'sum_scale' => 5.0, 'projects' => []]]];

        $lunch->addPackingColumn($table, $this->dish(10));
        ksort($table['columns']);

        $this->assertSame([1400, LunchKitchen::COLUMN], array_keys($table['columns']));
        $col = $table['columns'][LunchKitchen::COLUMN];
        $this->assertSame('Обіди', $col['label']);
        $this->assertSame(20, $col['count']);
        $this->assertEquals(40.0, $col['sum_scale']);
        $this->assertSame(['ТЕСТ від ХП' => 14, 'ТЕСТ від ХП 2' => 6], collect($col['projects'])->pluck('count', 'name')->all());
        $this->assertSame(5, $table['columns'][1400]['count']);
    }

    public function test_total_scale_for_shopping(): void
    {
        $lunch = LunchKitchen::forDate('2026-11-20');

        $this->assertEquals(40.0, LunchKitchen::totalScale($this->dish(10), $lunch->all()[10]));
    }
}
