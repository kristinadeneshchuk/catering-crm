<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CatalogImagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        Storage::fake('public');
    }

    public function test_attach_puts_files_into_gallery_and_gives_parent_category_a_photo(): void
    {
        $product = Product::first();
        Storage::disk('public')->put("products/{$product->slug}.webp", 'x');

        $this->artisan('catalog:attach-images')->assertSuccessful();

        $this->assertSame(["products/{$product->slug}.webp"], $product->fresh()->images->pluck('path')->all());
        $this->assertNotEmpty($product->fresh()->images->first()->source_url, 'джерело береться з product-image-sources.json');
        $this->assertSame("products/{$product->slug}.webp", $product->category->fresh()->image);
        $parent = $product->category->parent_id ? Category::find($product->category->parent_id) : null;
        if ($parent) {
            $this->assertSame("products/{$product->slug}.webp", $parent->image);
        }
    }

    public function test_attach_does_not_add_a_second_photo_next_to_the_one_from_admin(): void
    {
        $product = Product::first();
        $product->images()->create(['path' => 'products/manual.jpg']);
        Storage::disk('public')->put("products/{$product->slug}.webp", 'x');

        $this->artisan('catalog:attach-images')->assertSuccessful();

        $this->assertSame(['products/manual.jpg'], $product->fresh()->images->pluck('path')->all());
    }

    public function test_product_page_shows_photo_and_drops_empty_thumbnails(): void
    {
        $product = Product::first();

        $this->get(route('product', $product))->assertOk()->assertSee('загальний вигляд');

        $product->images()->create(['path' => 'products/a.webp']);

        $this->get(route('product', $product))->assertOk()
            ->assertSee('products/a-lg.webp')
            ->assertDontSee('загальний вигляд');
    }
}
