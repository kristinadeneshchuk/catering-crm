<?php

namespace Tests\Feature;

use App\Models\Ingredient;
use App\Services\Ai\InvoiceQuantity;
use Tests\Support\BuildsStockTestSchema;
use Tests\TestCase;

/**
 * Скільки прийшло насправді: у накладній рахують штуками, а на складі
 * позиція може вестись у кілограмах чи грамах.
 */
class InvoiceQuantityTest extends TestCase
{
    use BuildsStockTestSchema;

    protected InvoiceQuantity $quantity;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildStockSchema();
        $this->quantity = new InvoiceQuantity();
    }

    protected function ingredient(string $unit, ?float $packWeight = null, ?string $packUnit = null): Ingredient
    {
        return Ingredient::create([
            'name' => 'Тест', 'unit' => $unit, 'package_weight' => $packWeight,
            'package_unit' => $packUnit, 'is_packaged' => $packWeight !== null,
        ]);
    }

    public function test_the_same_unit_group_goes_through_unchanged(): void
    {
        $result = $this->quantity->resolve($this->ingredient('kg'), 10.6, 'кг');

        $this->assertSame(10.6, $result['qty']);
        $this->assertSame('кг', $result['unit']);
        $this->assertNull($result['note']);
    }

    public function test_pieces_from_the_invoice_become_weight(): void
    {
        // «Томатна паста 1560 г, 3 шт» при обліку в кг — це 4,68 кг, а не 3.
        $result = $this->quantity->resolve($this->ingredient('kg'), 3, 'шт', 1560, 'г');

        $this->assertSame(4680.0, $result['qty']);
        $this->assertSame('г', $result['unit']);
        $this->assertStringContainsString('3 шт × 1 560 г', $result['note']);
    }

    public function test_packaging_from_the_item_card_is_the_fallback(): void
    {
        // Фасування не видно в накладній, але воно є в картці товару.
        $result = $this->quantity->resolve($this->ingredient('kg', 2.4, 'шт'), 2, 'шт');

        $this->assertSame(4.8, $result['qty']);
        $this->assertSame('кг', $result['unit']);
    }

    public function test_litres_become_packs_when_the_item_is_counted_in_pieces(): void
    {
        // «Молоко пакет 0,9 л», у накладній 15 л, облік — у штуках.
        $result = $this->quantity->resolve($this->ingredient('pcs'), 15, 'л', 0.9, 'л');

        $this->assertSame(16.667, $result['qty']);
        $this->assertSame('шт', $result['unit']);
    }

    public function test_an_unknown_pack_size_is_flagged_for_a_human(): void
    {
        // Рис обліковується в грамах, у накладній 5 шт, фасування невідоме.
        $result = $this->quantity->resolve($this->ingredient('g'), 5, 'шт');

        $this->assertSame(5.0, $result['qty'], 'цифру не вигадуємо');
        $this->assertArrayHasKey('warning', $result);
        $this->assertStringContainsString('вкажіть фасування вручну', $result['warning']);
    }

    public function test_grams_in_the_invoice_against_kilograms_in_the_card(): void
    {
        $result = $this->quantity->resolve($this->ingredient('kg'), 500, 'г');

        $this->assertSame(500.0, $result['qty']);
        $this->assertSame('г', $result['unit'], 'переведе сама модель документа');
    }
}
