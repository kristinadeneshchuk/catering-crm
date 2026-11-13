<?php

namespace Tests\Feature;

use App\Models\LunchOrder;
use App\Models\LunchOrderLine;
use App\Services\Kitchen\LunchAssembly;
use Tests\TestCase;

class LunchAssemblyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        (require database_path('migrations/2026_11_11_100000_create_lunch_orders.php'))->up();

        $main  = ['dish_id' => 1, 'name' => 'Курка з булгуром', 'kind' => 'main', 'weight_g' => 287];
        $light = ['dish_id' => 2, 'name' => 'Салат з кіноа', 'kind' => 'light', 'weight_g' => 177];
        $extra = ['dish_id' => 3, 'name' => 'Крем-суп', 'kind' => 'extra', 'weight_g' => 250];

        $a = LunchOrder::create(['date' => '2026-11-16', 'company_id' => 1, 'company_name' => 'ТЕСТ від ХП', 'sets' => 3, 'amount' => 1140,
            'delivery_window' => '08:30–09:30', 'people' => [
                ['name' => 'Ірина П.', 'lines' => [$light]],
                ['name' => 'Андрій Шевченко', 'lines' => [$extra, $light, $main]],
                ['name' => 'Богдан Мельник', 'lines' => [$main, $light]],
            ]]);
        foreach ([[$main, 2], [$light, 3], [$extra, 1]] as [$d, $q]) {
            LunchOrderLine::create(['lunch_order_id' => $a->id, 'dish_id' => $d['dish_id'], 'name' => $d['name'], 'kind' => $d['kind'], 'qty' => $q, 'weight_g' => $d['weight_g']]);
        }

        // Знімок без розбивки по людях.
        $b = LunchOrder::create(['date' => '2026-11-16', 'company_id' => 2, 'company_name' => 'ТЕСТ від ХП 2', 'sets' => 2, 'amount' => 700]);
        LunchOrderLine::create(['lunch_order_id' => $b->id, 'dish_id' => 1, 'name' => 'Курка з булгуром', 'kind' => 'main', 'qty' => 2, 'weight_g' => 287]);

        LunchOrder::create(['date' => '2026-11-17', 'company_id' => 1, 'company_name' => 'ТЕСТ від ХП', 'sets' => 9, 'amount' => 1,
            'people' => [['name' => 'Інший день', 'lines' => [$main]]]]);
    }

    public function test_people_go_alphabetically_with_dishes_main_first(): void
    {
        $c = LunchAssembly::forDate('2026-11-16')[0];

        $this->assertSame(['Андрій Шевченко', 'Богдан Мельник', 'Ірина П.'], array_column($c['people'], 'name'));
        $this->assertSame(['main', 'light', 'extra'], array_column($c['people'][0]['items'], 'kind'));
    }

    public function test_each_box_gets_a_sticker_numbered_within_the_persons_kit(): void
    {
        $stickers = LunchAssembly::stickers('2026-11-16', withBag: false);
        $named = array_values(array_filter($stickers, fn ($s) => $s['person'] === 'Андрій Шевченко'));

        $this->assertCount(3, $named);
        $this->assertSame([[1, 3], [2, 3], [3, 3]], array_map(fn ($s) => [$s['n'], $s['of']], $named));
        // 6 боксів з іменами + 2 без імені (компанія без розбивки по людях); інший день не потрапив.
        $this->assertCount(8, $stickers);
        $this->assertCount(2, array_filter($stickers, fn ($s) => $s['person'] === null));
    }

    public function test_bag_sticker_comes_right_before_the_persons_boxes(): void
    {
        $stickers = LunchAssembly::stickers('2026-11-16');
        $types = array_map(fn ($s) => $s['type'].':'.($s['person'] ?? '-'), array_slice($stickers, 0, 4));

        $this->assertSame(['bag:Андрій Шевченко', 'box:Андрій Шевченко', 'box:Андрій Шевченко', 'box:Андрій Шевченко'], $types);
        $this->assertCount(3, array_filter($stickers, fn ($s) => $s['type'] === 'bag'));
    }

    public function test_print_pages_have_no_money_and_need_login(): void
    {
        $this->get('/print/lunch-stickers?day=2026-11-16')->assertRedirect();

        $html = app(\App\Http\Controllers\LunchPrintController::class)->stickers(new \Illuminate\Http\Request(['day' => '2026-11-16']))->render()
            .app(\App\Http\Controllers\LunchPrintController::class)->assembly(new \Illuminate\Http\Request(['day' => '2026-11-16']))->render();

        $this->assertStringContainsString('Андрій Шевченко', $html);
        $this->assertStringContainsString('08:30–09:30', $html);
        foreach (['1140', '1 140', '₴', 'грн'] as $money) {
            $this->assertStringNotContainsString($money, $html);
        }
    }
}
