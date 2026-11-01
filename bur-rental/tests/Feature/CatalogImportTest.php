<?php

namespace Tests\Feature;

use App\Models\Branch;
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

    public function test_import_puts_one_unit_in_each_branch_and_keeps_heavy_in_kyiv(): void
    {
        Storage::put('import/stock.json', json_encode(['products' => [
            ['url' => 'https://x/a', 'name' => 'Дриль Bosch', 'price' => 100, 'category_slug' => 'dryli', 'brand' => 'Bosch', 'weight' => 2],
            ['url' => 'https://x/b', 'name' => 'Віброплита Wacker', 'price' => 900, 'category_slug' => 'vibroplyty-reversyvni', 'brand' => 'Wacker Neuson', 'weight' => 160],
            ['url' => 'https://x/c', 'name' => 'Плиткоріз Rubi DC-250', 'price' => 500, 'category_slug' => 'pyly', 'brand' => 'Rubi'],
        ]], JSON_UNESCAPED_UNICODE));

        $this->artisan('catalog:import', ['file' => 'import/stock.json'])->assertSuccessful();

        $light = Product::withoutGlobalScopes()->where('source_url', 'https://x/a')->firstOrFail();
        $heavy = Product::withoutGlobalScopes()->where('source_url', 'https://x/b')->firstOrFail();
        $new = Product::withoutGlobalScopes()->where('source_url', 'https://x/c')->firstOrFail();

        $this->assertSame('dryli', $light->category->slug);
        $this->assertSame('Bosch', $light->brand->name);
        $this->assertSame(Branch::count(), $light->branches()->count());
        $this->assertTrue($heavy->branches->every(fn ($b) => $b->city->slug === 'kyiv'));
        $this->assertSame(1, $heavy->branches->first()->pivot->qty);
        $this->assertSame('Rubi', $new->brand->name);
        $this->assertSame('rubi', $new->brand->slug);

        // Повторний імпорт не чіпає складу, який уже є.
        $heavy->branches()->updateExistingPivot($heavy->branches->first()->id, ['qty' => 4]);
        $this->artisan('catalog:import', ['file' => 'import/stock.json'])->assertSuccessful();
        $this->assertSame(4, $heavy->fresh()->branches->first()->pivot->qty);
    }
}
