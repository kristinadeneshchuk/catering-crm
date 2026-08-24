<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Services\Search\ProductSearch;
use App\Services\Search\SearchTerms;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Пошук перевіряється на тому, як пишуть люди, а не на ідеальних запитах.
 */
class SearchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    private function names(string $query): array
    {
        return app(ProductSearch::class)->find($query)->products->pluck('name')->all();
    }

    private function assertFinds(string $query, string $expected): void
    {
        $names = $this->names($query);

        $this->assertNotEmpty($names, "«{$query}» не знайшов нічого");
        $this->assertTrue(
            (bool) array_filter($names, fn (string $n) => str_contains($n, $expected)),
            "«{$query}» мав знайти «{$expected}», а знайшов: ".implode(', ', $names)
        );
    }

    public function test_search_index_is_built_on_save(): void
    {
        $product = Product::where('slug', 'bosch-gbh-2-26-dre')->firstOrFail();

        // Бренд і категорія лежать у пошуковому рядку разом із назвою.
        $this->assertStringContainsString('bosch', $product->search_text);
        $this->assertStringContainsString('перфоратор', $product->search_text);
    }

    public function test_brand_typed_in_cyrillic_finds_latin_catalog(): void
    {
        $this->assertFinds('бош', 'GBH');
        $this->assertFinds('макіта', 'HR2470');
    }

    public function test_wrong_keyboard_layout_still_finds_the_tool(): void
    {
        // «gthajhfnjh» — це «перфоратор», набраний в англійській розкладці.
        $this->assertFinds('gthajhfnjh', 'Перфоратор');
    }

    public function test_typo_falls_back_to_the_closest_name(): void
    {
        $result = app(ProductSearch::class)->find('перфаратор');

        $this->assertTrue($result->corrected, 'сторінка має чесно сказати, що це виправлення');
        $this->assertStringContainsString('Перфоратор', $result->products->first()->name);
    }

    public function test_colloquial_and_russian_names_map_to_the_catalog(): void
    {
        $this->assertFinds('шуруповерт', 'Шурупокрут');
        $this->assertFinds('бетономешалка', 'Бетонозмішувач');
        $this->assertFinds('лестница', 'Драбина');
        $this->assertFinds('обогреватель', 'гармата');
    }

    public function test_words_are_combined_not_alternated(): void
    {
        // «бош перфоратор» — це перфоратори Bosch, а не все Bosch плюс усі
        // перфоратори: кожне слово має знайтися в товарі.
        foreach ($this->names('бош перфоратор') as $name) {
            $this->assertStringContainsString('Перфоратор', $name);
        }

        $this->assertNotEmpty($this->names('бош перфоратор'));
    }

    public function test_name_matches_outrank_category_matches(): void
    {
        // «болгарка» — і назва товарів, і слово з категорії. Нагорі мають бути
        // самі болгарки.
        $this->assertStringContainsString('Болгарка', $this->names('болгарка')[0]);
    }

    public function test_nonsense_query_returns_the_empty_state(): void
    {
        $this->assertSame([], $this->names('ыфваыфва'));

        $this->get('/search?q='.urlencode('ыфваыфва'))
            ->assertOk()
            ->assertSee('Нічого не знайшли');
    }

    public function test_search_page_finds_categories_by_the_same_rules(): void
    {
        $this->get('/search?q='.urlencode('gthajhfnjh'))
            ->assertOk()
            ->assertSee('Перфоратори');
    }

    public function test_reindex_command_rebuilds_the_index(): void
    {
        Product::withoutGlobalScope('published')->update(['search_text' => null]);

        $this->artisan('search:reindex')->assertSuccessful();

        $this->assertNotEmpty($this->names('перфоратор'));
    }

    public function test_short_words_are_compared_strictly(): void
    {
        // На коротких словах Levenshtein шкодить: «бур» і «бор» — різні речі.
        $terms = app(SearchTerms::class);

        $this->assertFalse($terms->looksLike('бур', 'бор'));
        $this->assertTrue($terms->looksLike('перфоратор', 'перфаратор'));
    }
}
