<?php

namespace Tests\Unit;

use App\Models\Ingredient;
use App\Support\PurchaseQty;
use PHPUnit\Framework\TestCase;

class PurchaseQtyTest extends TestCase
{
    public function test_small_amounts_are_shown_in_grams(): void
    {
        $this->assertSame('60 г', PurchaseQty::format(0.06, 'кг'));
        $this->assertSame('1 г', PurchaseQty::format(0.0004, 'кг'));
        $this->assertSame('50 мл', PurchaseQty::format(0.05, 'л'));
        $this->assertSame('5.99 кг', PurchaseQty::format(5.99, 'кг'));
        $this->assertSame('0.00 кг', PurchaseQty::format(0, 'кг'));
    }

    public function test_pieces_are_counted_from_the_piece_weight(): void
    {
        $bun = new Ingredient(['unit' => 'кг', 'package_weight' => 0.2, 'package_unit' => 'кг']);
        $this->assertSame('1 шт по 200 г', PurchaseQty::pieces($bun, 0.06));

        $eggs = new Ingredient(['unit' => 'кг', 'package_weight' => 0.055, 'package_unit' => 'кг']);
        $this->assertSame('30 шт по 55 г', PurchaseQty::pieces($eggs, 1.65));

        $oil = new Ingredient(['unit' => 'л', 'package_weight' => 5, 'package_unit' => 'л']);
        $this->assertSame('2 шт по 5 л', PurchaseQty::pieces($oil, 7));

        $this->assertNull(PurchaseQty::pieces(new Ingredient(['unit' => 'кг']), 1.0));
    }
}
