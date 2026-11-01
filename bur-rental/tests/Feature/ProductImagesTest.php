<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductImage;
use App\Services\ProductImages;
use App\Services\ProductImageSources;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Tests\TestCase;

class ProductImagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        Storage::fake('public');
    }

    private function jpeg(int $w = 1200, int $h = 900): string
    {
        $img = imagecreatetruecolor($w, $h);
        imagefill($img, 0, 0, imagecolorallocate($img, 14, 91, 70));
        ob_start();
        imagejpeg($img);

        return (string) ob_get_clean();
    }

    private function product(string $slug = 'bosch-gbh-2-26-dre'): Product
    {
        return Product::where('slug', $slug)->firstOrFail();
    }

    public function test_upload_makes_three_webp_sizes_and_remembers_dimensions(): void
    {
        $image = app(ProductImages::class)->storeFromBytes($this->product(), $this->jpeg());

        foreach (ProductImage::SIZES as $size => $width) {
            Storage::disk('public')->assertExists($image->variantPath($size));
            $info = getimagesizefromstring(Storage::disk('public')->get($image->variantPath($size)));
            $this->assertSame('image/webp', $info['mime']);
            $this->assertSame(min(1200, $width), $info[0]);
        }

        $this->assertSame([1200, 900], [$image->fresh()->width, $image->fresh()->height]);
    }

    public function test_not_an_image_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(ProductImages::class)->storeFromBytes($this->product(), '<html>not an image</html>');
    }

    public function test_deleting_a_photo_removes_its_files(): void
    {
        $image = app(ProductImages::class)->storeFromBytes($this->product(), $this->jpeg());
        $image->delete();

        Storage::disk('public')->assertMissing($image->path);
        Storage::disk('public')->assertMissing($image->variantPath('md'));
    }

    public function test_product_page_shows_the_photo_in_gallery_schema_and_link_preview(): void
    {
        $product = $this->product();
        $image = app(ProductImages::class)->storeFromBytes($product, $this->jpeg());

        $html = $this->get(route('product', $product, false))->assertOk()->getContent();

        $this->assertStringContainsString('src="'.$image->url('lg').'"', $html);
        $this->assertStringContainsString('<meta property="og:image" content="'.$image->url('lg').'">', $html);
        // Розмітка Product: дістаємо JSON і перевіряємо поле, а не форму екранування.
        preg_match_all('~<script type="application/ld\+json">(.*?)</script>~s', $html, $blocks);
        $schema = collect($blocks[1])->map(fn ($json) => json_decode($json, true))->firstWhere('@type', 'Product');
        $this->assertSame([$image->url('lg')], $schema['image']);
        $this->assertStringContainsString('fetchpriority="high"', $html);
    }

    public function test_product_without_photo_keeps_the_placeholder_and_no_og_image(): void
    {
        $html = $this->get(route('product', $this->product('makita-hr2470'), false))->assertOk()->getContent();

        $this->assertStringNotContainsString('og:image', $html);
        $this->assertStringContainsString('загальний вигляд', $html);
    }

    public function test_catalog_card_shows_the_main_photo(): void
    {
        $product = $this->product();
        $image = app(ProductImages::class)->storeFromBytes($product, $this->jpeg());

        $this->get(route('category', $product->category, false))
            ->assertOk()
            ->assertSee($image->url('md'), false);
    }

    public function test_photo_is_taken_from_the_manufacturer_page_og_image(): void
    {
        Http::fake([
            'maker.example/product/gbh' => Http::response(
                '<html><head><meta property="og:image" content="/media/gbh.jpg"></head></html>',
                200, ['Content-Type' => 'text/html; charset=utf-8']
            ),
            'maker.example/media/gbh.jpg' => Http::response($this->jpeg(), 200, ['Content-Type' => 'image/jpeg']),
        ]);

        $image = app(ProductImageSources::class)->import($this->product(), 'https://maker.example/product/gbh');

        $this->assertSame('https://maker.example/product/gbh', $image->source_url);
        Storage::disk('public')->assertExists($image->variantPath('lg'));
    }

    public function test_json_ld_product_image_is_used_when_there_is_no_og_image(): void
    {
        $html = '<script type="application/ld+json">{"@context":"https://schema.org","@type":"Product","image":["https://cdn.maker.example/a.jpg"]}</script>';

        $this->assertSame(
            'https://cdn.maker.example/a.jpg',
            app(ProductImageSources::class)->imageFromPage($html, 'https://maker.example/p')
        );
    }

    public function test_fetch_command_fills_only_products_without_photos(): void
    {
        $withPhoto = $this->product();
        app(ProductImages::class)->storeFromBytes($withPhoto, $this->jpeg());

        $list = tempnam(sys_get_temp_dir(), 'src').'.php';
        file_put_contents($list, '<?php return '.var_export([
            'bosch-gbh-2-26-dre' => ['https://maker.example/should-not-be-called'],
            'makita-hr2470' => ['https://maker.example/broken', 'https://maker.example/hr2470.jpg'],
        ], true).';');

        Http::fake([
            'maker.example/broken' => Http::response('', 404),
            'maker.example/hr2470.jpg' => Http::response($this->jpeg(), 200, ['Content-Type' => 'image/jpeg']),
            '*' => Http::response('', 500),
        ]);

        $this->artisan('products:fetch-images', ['--list' => $list, '--slug' => ['bosch-gbh-2-26-dre', 'makita-hr2470']])
            ->assertSuccessful();

        $this->assertSame(1, $withPhoto->images()->count());
        $this->assertSame(1, $this->product('makita-hr2470')->images()->count());
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'should-not-be-called'));

        @unlink($list);
    }

    public function test_every_image_source_points_to_a_real_product(): void
    {
        $sources = require database_path('seeders/data/product-image-sources.php');
        $unknown = array_diff(array_keys($sources), Product::withoutGlobalScopes()->pluck('slug')->all());

        $this->assertEmpty($unknown, 'Немає таких товарів: '.implode(', ', $unknown));

        foreach ($sources as $slug => $urls) {
            foreach ($urls as $url) {
                $this->assertMatchesRegularExpression('~^https://~', $url, "{$slug}: не https");
            }
        }
    }

    public function test_relative_photo_paths_resolve_against_the_page(): void
    {
        $sources = app(ProductImageSources::class);
        $page = 'https://maker.example/catalog/item';

        $html = fn (string $src) => '<meta property="og:image" content="'.$src.'">';

        $this->assertSame('https://maker.example/media/a.jpg', $sources->imageFromPage($html('/media/a.jpg'), $page));
        $this->assertSame('https://maker.example/catalog/img/a.jpg', $sources->imageFromPage($html('img/a.jpg'), $page));
        $this->assertSame('https://cdn.example/a.jpg', $sources->imageFromPage($html('//cdn.example/a.jpg'), $page));
        $this->assertSame('https://cdn.example/a.jpg', $sources->imageFromPage($html('https://cdn.example/a.jpg'), $page));
    }
}
