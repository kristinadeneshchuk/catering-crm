<?php

namespace Tests\Feature;

use App\Models\Product;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CatalogImportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        Storage::fake('local');
    }

    private function importFixture(): void
    {
        Storage::put('import/test.json', json_encode([
            'source' => 'https://budprokat.kiev.ua/ua/',
            'products' => [
                [
                    'url' => 'https://budprokat.kiev.ua/ua/perforatory/gbh-11-de/',
                    'name' => 'Перфоратор Bosch GBH 11 DE',
                    'price' => 400,
                    'deposit' => 5000,
                    'description' => 'Важкий перфоратор.',
                    'specs' => ['Потужність' => '1500 Вт'],
                    'category' => 'Оренда перфораторів (імпорт)',
                    'breadcrumbs' => [],
                ],
            ],
        ], JSON_UNESCAPED_UNICODE));

        $this->artisan('catalog:import', ['file' => 'import/test.json'])->assertSuccessful();
    }

    public function test_imported_products_arrive_unpublished_and_hidden_from_the_site(): void
    {
        $this->importFixture();

        $product = Product::withoutGlobalScopes()
            ->where('source_url', 'https://budprokat.kiev.ua/ua/perforatory/gbh-11-de/')
            ->firstOrFail();

        $this->assertFalse($product->published);
        $this->assertSame(400, $product->base_price);
        $this->assertSame(5000, $product->deposit);
        $this->assertCount(3, $product->tiers);

        // Вітрина чернетку не бачить: ні сторінки, ні картки в лістингу.
        $this->get('/instrument/'.$product->slug)->assertNotFound();
        $this->get('/catalog/'.$product->category->slug)->assertOk()->assertDontSee($product->name);

        // Бренд розпізнано з назви.
        $this->assertSame('Bosch', $product->brand->name);
    }

    public function test_reimport_updates_instead_of_duplicating(): void
    {
        $this->importFixture();
        $this->importFixture();

        $this->assertSame(1, Product::withoutGlobalScopes()
            ->where('source_url', 'https://budprokat.kiev.ua/ua/perforatory/gbh-11-de/')
            ->count());
    }

    public function test_publishing_puts_the_product_on_the_site(): void
    {
        $this->importFixture();

        $product = Product::withoutGlobalScopes()->whereNotNull('source_url')->firstOrFail();
        $product->update(['published' => true]);

        $this->get('/instrument/'.$product->slug)->assertOk()->assertSee($product->name);
    }
}
