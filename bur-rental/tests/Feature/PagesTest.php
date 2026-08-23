<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Category;
use App\Models\City;
use App\Models\Kit;
use App\Models\Product;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_every_public_page_renders(): void
    {
        $product = Product::where('slug', 'bosch-gbh-2-26-dre')->firstOrFail();
        $city = City::where('slug', 'kyiv')->firstOrFail();

        $urls = [
            '/',
            '/catalog',
            route('category', Category::where('slug', 'perforatory')->firstOrFail(), false),
            route('product', $product, false),
            '/kits',
            route('kit', Kit::first(), false),
            route('brand', Brand::where('slug', 'bosch')->firstOrFail(), false),
            '/booking',
            '/search?q=перфоратор',
            '/terms', '/delivery', '/returns', '/contacts', '/b2b',
            route('city', $city, false),
            route('branch', [$city, $city->branches->first()], false),
            route('district', [$city, $city->districts->first()], false),
        ];

        foreach ($urls as $url) {
            $this->get($url)->assertOk();
        }
    }

    public function test_sitemap_lists_the_catalog_and_robots_points_at_it(): void
    {
        config(['app.noindex' => false]);

        $product = Product::where('slug', 'bosch-gbh-2-26-dre')->firstOrFail();

        $this->get('/sitemap.xml')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/xml')
            ->assertSee(route('product', $product))
            ->assertSee(route('category', Category::where('slug', 'perforatory')->firstOrFail()));

        $this->get('/robots.txt')->assertOk()->assertSee(route('sitemap'));
    }

    public function test_staging_hides_both_robots_and_sitemap(): void
    {
        config(['app.noindex' => true]);

        $this->get('/robots.txt')->assertOk()->assertSee('Disallow: /');
        $this->get('/sitemap.xml')->assertNotFound();
    }

    public function test_missing_page_returns_a_useful_404(): void
    {
        // 404 має лишатися повноцінною сторінкою з пошуком, а не порожнім екраном.
        $this->get('/no-such-page')
            ->assertNotFound()
            ->assertSee('Такої сторінки немає');
    }

    public function test_city_choice_switches_phone_across_the_site(): void
    {
        $this->get('/kharkiv')->assertOk()->assertSee('067 245 80 90');

        // Вибір міста лишається на наступних сторінках.
        $this->get('/catalog')->assertOk()->assertSee('067 245 80 90');
    }

    public function test_listing_filters_by_brand_and_availability(): void
    {
        $url = route('category', Category::where('slug', 'perforatory')->firstOrFail(), false);

        $this->get($url.'?brand[]=makita')
            ->assertOk()
            ->assertSee('Makita')
            ->assertDontSee('GBH 2-26 DRE');
    }
}
