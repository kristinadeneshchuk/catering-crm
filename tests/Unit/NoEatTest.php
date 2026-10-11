<?php

namespace Tests\Unit;

use App\Models\Client;
use App\Models\Dish;
use App\Models\Ingredient;
use App\Models\Order;
use App\Support\NoEat;
use PHPUnit\Framework\TestCase;

class NoEatTest extends TestCase
{
    public function test_dish_exclusions_are_short_and_skip_semi_finished(): void
    {
        $client = new Client(); $client->allergies = 'Горіхи';
        $client->setRelation('replacementBundles', collect());
        $client->setRelation('ingredientExclusions', collect([new Ingredient(['name' => 'Молоко'])]));
        $dishes = collect(['Тирамісу н\ф', 'Сирники н/ф', 'А', 'Б', 'В', 'Г', 'Д', 'Е', 'Є'])->map(fn ($n) => new Dish(['name' => $n]));
        $client->setRelation('dishExclusions', $dishes);

        $order = new Order();
        $order->setRelation('client', $client);
        $order->setRelation('ingredientExclusions', collect());

        $this->assertSame('Горіхи. Без: молоко. Не давати: А, Б, В, Г, Д, Е … ще 1 страв', NoEat::summary($order));
    }
}
