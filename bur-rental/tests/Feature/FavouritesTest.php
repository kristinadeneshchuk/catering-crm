<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Product;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FavouritesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    private function product(string $slug = 'bosch-gbh-2-26-dre'): Product
    {
        return Product::where('slug', $slug)->firstOrFail();
    }

    public function test_guest_page_opens_and_offers_to_log_in(): void
    {
        // Обране працює без входу — інакше ми втрачаємо і закладку, і клієнта.
        $this->get('/favourites')->assertOk()->assertSee('Увійдіть');
    }

    public function test_guest_toggle_changes_nothing_on_the_server(): void
    {
        $this->post('/favourites/'.$this->product()->id)
            ->assertOk()
            ->assertJson(['guest' => true]);

        $this->assertDatabaseCount('favourites', 0);
    }

    public function test_logged_in_client_saves_and_removes(): void
    {
        $client = Client::create(['phone' => '380672458080']);
        $product = $this->product();

        $this->actingAs($client, 'client');

        $this->post('/favourites/'.$product->id)->assertJson(['saved' => true]);
        $this->assertTrue($client->favourites()->where('products.id', $product->id)->exists());

        $this->post('/favourites/'.$product->id)->assertJson(['saved' => false]);
        $this->assertFalse($client->favourites()->where('products.id', $product->id)->exists());
    }

    public function test_guest_list_is_merged_not_replaced_on_login(): void
    {
        $client = Client::create(['phone' => '380672458080']);
        $onServer = $this->product();
        $inBrowser = $this->product('makita-hr2470');

        $client->favourites()->attach($onServer->id);

        $this->actingAs($client, 'client');

        $this->post('/favourites-sync', ['ids' => [$inBrowser->id]])->assertOk();

        // Людина додавала обране і з телефона, і з ноутбука — жоден зі списків
        // не має права зникнути.
        $this->assertEqualsCanonicalizing(
            [$onServer->id, $inBrowser->id],
            $client->favourites()->pluck('products.id')->all()
        );
    }

    public function test_cabinet_shows_saved_items(): void
    {
        $client = Client::create(['phone' => '380672458080']);
        $client->favourites()->attach($this->product()->id);

        $this->actingAs($client, 'client');

        $this->get('/favourites')->assertOk()->assertSee('GBH 2-26 DRE');
        $this->get('/cabinet')->assertOk()->assertSee('GBH 2-26 DRE');
    }

    public function test_guest_items_endpoint_returns_only_asked_products(): void
    {
        $product = $this->product();

        $this->get('/favourites/items?ids='.$product->id)
            ->assertOk()
            ->assertSee('GBH 2-26 DRE')
            ->assertDontSee('HR2470');
    }

    public function test_sync_is_closed_to_guests(): void
    {
        $this->post('/favourites-sync', ['ids' => [$this->product()->id]])->assertStatus(401);
        $this->assertDatabaseCount('favourites', 0);
    }
}
