<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\City;
use App\Models\Lead;
use App\Models\Product;
use App\Models\Review;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Те, що бачить робот. Кожен тест тут — про сторінку видачі, а не про верстку.
 */
class SeoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        config(['app.noindex' => false]);
    }

    /** @return array<int, array<string, mixed>> */
    private function schemas(string $url): array
    {
        $html = $this->get($url)->assertOk()->getContent();

        preg_match_all('~<script type="application/ld\+json">(.*?)</script>~s', $html, $m);

        return array_map(fn (string $json) => json_decode($json, true), $m[1]);
    }

    private function typesOn(string $url): array
    {
        $types = [];

        foreach ($this->schemas($url) as $schema) {
            foreach ($schema['@graph'] ?? [$schema] as $node) {
                $types[] = $node['@type'] ?? null;
            }
        }

        return array_filter($types);
    }

    public function test_service_pages_are_closed_from_the_index(): void
    {
        // Пошук без noindex плодить нескінченні тонкі сторінки під кожен запит.
        foreach (['/search?q=перфоратор', '/favourites', '/booking'] as $url) {
            $this->get($url)->assertOk()->assertSee('name="robots" content="noindex', false);
        }
    }

    public function test_catalog_pages_stay_open(): void
    {
        $category = Category::where('slug', 'perforatory')->firstOrFail();

        foreach (['/', '/catalog', route('category', $category, false)] as $url) {
            $this->get($url)->assertOk()->assertDontSee('content="noindex', false);
        }
    }

    public function test_link_preview_tags_are_filled_from_the_page(): void
    {
        $product = Product::where('slug', 'bosch-gbh-2-26-dre')->firstOrFail();

        $this->get(route('product', $product, false))
            ->assertOk()
            ->assertSee('property="og:title" content="Оренда: Bosch Перфоратор', false)
            ->assertSee('property="og:url"', false)
            ->assertSee('name="twitter:card"', false);
    }

    public function test_site_wide_schema_is_present_once(): void
    {
        $types = $this->typesOn('/');

        $this->assertContains('Organization', $types);
        $this->assertContains('WebSite', $types);
        $this->assertSame(1, count(array_keys($types, 'Organization')));
    }

    public function test_branch_page_describes_a_physical_point(): void
    {
        $city = City::where('slug', 'kyiv')->firstOrFail();
        $branch = $city->branches()->firstOrFail();

        $schemas = $this->schemas(route('branch', [$city, $branch], false));
        $local = collect($schemas)->firstWhere('@type', 'LocalBusiness');

        $this->assertNotNull($local, 'філія має бути розмічена як LocalBusiness');
        $this->assertSame($branch->address, $local['address']['streetAddress']);
        $this->assertSame($city->phone, $local['telephone']);
    }

    public function test_category_page_is_marked_up_as_a_list(): void
    {
        $category = Category::where('slug', 'perforatory')->firstOrFail();

        $list = collect($this->schemas(route('category', $category, false)))
            ->firstWhere('@type', 'ItemList');

        $this->assertNotNull($list);
        $this->assertNotEmpty($list['itemListElement']);
        $this->assertSame(1, $list['itemListElement'][0]['position']);
    }

    public function test_rating_is_published_only_when_real_reviews_exist(): void
    {
        $product = Product::whereDoesntHave('reviews')->firstOrFail();

        // У товару стоять рейтинг і кількість відгуків, але самих відгуків
        // немає: розмічати їх — порушення правил Google із санкцією на домен.
        $product->update(['rating' => 4.9, 'reviews_count' => 148]);

        $schema = collect($this->schemas(route('product', $product, false)))
            ->firstWhere('@type', 'Product');

        $this->assertArrayNotHasKey('aggregateRating', $schema);

        Review::create([
            'reviewable_type' => Product::class,
            'reviewable_id' => $product->id,
            'author' => 'Олег',
            'rating' => 5,
            'body' => 'Взяв на вихідні, все працює.',
            'published_at' => now(),
        ]);

        $schema = collect($this->schemas(route('product', $product->fresh(), false)))
            ->firstWhere('@type', 'Product');

        $this->assertSame(1, $schema['aggregateRating']['reviewCount']);
    }

    public function test_offer_availability_follows_the_real_stock(): void
    {
        $product = Product::where('slug', 'bosch-gbh-2-26-dre')->firstOrFail();

        $schema = collect($this->schemas(route('product', $product, false)))
            ->firstWhere('@type', 'Product');

        // Оренда, а не продаж — це окрема бізнес-функція в schema.org.
        $this->assertSame('https://schema.org/LeaseOut', $schema['offers']['businessFunction']);
        $this->assertStringContainsString('Stock', $schema['offers']['availability']);
    }

    public function test_sitemap_carries_lastmod(): void
    {
        $this->get('/sitemap.xml')
            ->assertOk()
            ->assertSee('<lastmod>'.Product::first()->updated_at->toDateString().'</lastmod>', false);
    }

    public function test_icons_and_manifest_are_linked(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('rel="apple-touch-icon"', false)
            ->assertSee('rel="manifest"', false);
    }

    public function test_demo_reviews_never_reach_the_site(): void
    {
        // Сиди наповнюють базу відгуками для показу замовнику. На сайті їх
        // бути не може: вигаданий відгук — це обман клієнта і привід для
        // санкцій пошуковика.
        $this->assertGreaterThan(0, Review::withoutGlobalScope('real')->where('demo', true)->count());
        $this->assertSame(0, Review::count());

        $this->get('/')->assertOk()->assertDontSee('Відгуки з Google');
    }

    public function test_no_invented_rating_is_printed_anywhere(): void
    {
        $product = Product::where('slug', 'bosch-gbh-2-26-dre')->firstOrFail();
        $city = City::where('slug', 'kyiv')->firstOrFail();

        foreach (['/', route('product', $product, false), route('city', $city, false)] as $url) {
            // «4,8» і «★» на сторінці без жодного відгуку — саме те, за що
            // прилітає ручна санкція.
            $this->get($url)->assertOk()->assertDontSee('★', false);
        }
    }

    public function test_real_review_brings_the_rating_back(): void
    {
        $product = Product::where('slug', 'bosch-gbh-2-26-dre')->firstOrFail();

        Review::create([
            'reviewable_type' => Product::class,
            'reviewable_id' => $product->id,
            'author' => 'Олег',
            'rating' => 5,
            'body' => 'Взяв на вихідні, все працює.',
            'published_at' => now(),
        ]);

        $this->get(route('product', $product, false))->assertOk()->assertSee('★', false);
    }

    public function test_launch_check_stops_a_release_with_demo_data(): void
    {
        config(['app.url' => 'http://localhost', 'content.demo_reviews' => true]);

        // Команда мусить бути червоною, поки в базі демо-контакти, а в конфізі
        // ввімкнені вигадані відгуки.
        $this->artisan('check:launch')->assertFailed();
    }

    public function test_campaign_from_the_first_visit_reaches_the_lead(): void
    {
        // Клієнт прийшов з оголошення на категорію, поблукав і лише потім
        // лишив заявку — мітки в адресі на той момент давно немає.
        $this->get('/catalog?utm_source=google&utm_medium=cpc&utm_campaign=perforator-kyiv');
        $this->get('/kits');

        $this->post('/leads', [
            'kind' => 'callback',
            'name' => 'Олег',
            'phone' => '+380 67 245 80 80',
        ]);

        $lead = Lead::latest('id')->firstOrFail();

        $this->assertSame('google', $lead->campaign['utm_source']);
        $this->assertSame('google / cpc / perforator-kyiv', $lead->campaign_label);
    }

    public function test_lead_without_a_campaign_is_marked_as_direct(): void
    {
        $this->post('/leads', [
            'kind' => 'callback',
            'name' => 'Олег',
            'phone' => '+380 67 245 80 80',
        ]);

        $this->assertNull(Lead::latest('id')->firstOrFail()->campaign_label);
    }
}
