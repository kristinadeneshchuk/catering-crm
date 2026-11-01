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

    public function test_attach_links_files_and_gives_parent_category_a_photo(): void
    {
        $product = Product::first();
        Storage::disk('public')->put("products/{$product->slug}.webp", 'x');

        $this->artisan('catalog:attach-images')->assertSuccessful();

        $this->assertSame("products/{$product->slug}.webp", $product->fresh()->image);
        $this->assertSame($product->fresh()->image, $product->category->fresh()->image);
        $parent = $product->category->parent_id ? Category::find($product->category->parent_id) : null;
        if ($parent) {
            $this->assertSame($product->fresh()->image, $parent->image);
        }
    }

    public function test_attach_does_not_overwrite_photo_set_in_admin(): void
    {
        $product = Product::first();
        $product->update(['image' => 'products/manual.jpg']);
        Storage::disk('public')->put("products/{$product->slug}.webp", 'x');

        $this->artisan('catalog:attach-images')->assertSuccessful();

        $this->assertSame('products/manual.jpg', $product->fresh()->image);
    }

    public function test_product_page_shows_photo_and_drops_empty_thumbnails(): void
    {
        $product = Product::first();

        $this->get(route('product', $product))->assertOk()->assertSee('загальний вигляд');

        $product->update(['image' => 'products/a.webp']);

        $this->get(route('product', $product))->assertOk()
            ->assertSee('products/a.webp')
            ->assertDontSee('загальний вигляд');
    }
}
