<?php

namespace Tests\Unit;

use App\Models\Product;
use App\Services\RentalPricing;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RentalPricingTest extends TestCase
{
    use RefreshDatabase;

    private RentalPricing $pricing;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->pricing = new RentalPricing;
    }

    private function product(): Product
    {
        return Product::with('tiers')->where('slug', 'bosch-gbh-2-26-dre')->firstOrFail();
    }

    public function test_days_are_counted_inclusively(): void
    {
        // Оренда з 8-го по 12-те — це п'ять діб, а не чотири.
        $this->assertSame(5, $this->pricing->days('2026-08-08', '2026-08-12'));
        $this->assertSame(1, $this->pricing->days('2026-08-08', '2026-08-08'));
    }

    public function test_price_per_day_falls_with_the_term(): void
    {
        $product = $this->product();

        $this->assertSame(350, $this->pricing->pricePerDay($product, 1));
        $this->assertSame(350, $this->pricing->pricePerDay($product, 2));
        $this->assertSame(290, $this->pricing->pricePerDay($product, 3));
        $this->assertSame(290, $this->pricing->pricePerDay($product, 6));
        $this->assertSame(240, $this->pricing->pricePerDay($product, 7));
        $this->assertSame(240, $this->pricing->pricePerDay($product, 30));
    }

    public function test_savings_are_measured_against_the_base_tier(): void
    {
        $this->assertSame(7 * (350 - 240), $this->pricing->savings($this->product(), 7));
    }

    public function test_availability_respects_busy_dates(): void
    {
        $product = $this->product()->load('unavailableDates');
        $branch = $product->branches->first();
        $busy = $product->unavailableDates->where('branch_id', $branch->id)->first()->date->toDateString();

        $this->assertFalse($product->isFreeAt($branch, $busy, $busy));
    }
}
