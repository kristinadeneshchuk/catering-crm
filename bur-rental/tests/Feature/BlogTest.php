<?php

namespace Tests\Feature;

use App\Models\Article;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BlogTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        config(['app.noindex' => false]);
    }

    public function test_blog_and_articles_open(): void
    {
        $this->get('/blog')->assertOk()->assertSee('Як зробити роботу');

        foreach (Article::all() as $article) {
            $this->get(route('article', $article, false))
                ->assertOk()
                ->assertSee($article->title, false);
        }
    }

    public function test_markdown_becomes_html_and_raw_markup_is_stripped(): void
    {
        $article = Article::create([
            'slug' => 'test',
            'title' => 'Тест',
            'excerpt' => 'Анонс',
            'body' => "## Заголовок\n\nТекст із **жирним**.\n\n<script>alert(1)</script>",
        ]);

        // Автор пише текст, а не верстку: вставлений скрипт не має доїхати
        // до сторінки навіть із адмінки.
        $this->assertStringContainsString('<h2>Заголовок</h2>', $article->html);
        $this->assertStringContainsString('<strong>жирним</strong>', $article->html);
        $this->assertStringNotContainsString('<script>', $article->html);
    }

    public function test_article_leads_somewhere(): void
    {
        // Стаття без переходу далі — просто текст. Кожна веде або в комплект
        // під задачу, або принаймні в категорію.
        foreach (Article::all() as $article) {
            $this->assertTrue(
                $article->kit_id !== null || $article->category_id !== null,
                "стаття «{$article->title}» нікуди не веде"
            );
        }
    }

    public function test_kit_block_is_shown_on_the_article(): void
    {
        $article = Article::whereNotNull('kit_id')->firstOrFail();

        $this->get(route('article', $article, false))
            ->assertOk()
            ->assertSee('Готовий комплект')
            ->assertSee($article->kit->name);
    }

    public function test_draft_is_invisible_on_the_site(): void
    {
        $article = Article::first();
        $article->update(['published' => false]);

        $this->get(route('article', $article->slug, false))->assertNotFound();
        $this->get('/blog')->assertOk()->assertDontSee($article->title, false);
    }

    public function test_articles_are_in_the_sitemap_and_marked_up(): void
    {
        $article = Article::first();

        $this->get('/sitemap.xml')->assertOk()->assertSee(route('article', $article), false);

        $this->get(route('article', $article, false))
            ->assertOk()
            ->assertSee('"@type":"Article"', false);
    }

    public function test_internal_links_in_articles_are_not_broken(): void
    {
        // Стаття веде читача вглиб каталогу — і робот ходить тими самими
        // посиланнями. Товар перейменували, slug змінився — і замість оренди
        // читач отримує 404. Дешевше зловити це тестом.
        foreach (Article::all() as $article) {
            preg_match_all('~\]\((/[a-z0-9/-]+)\)~', $article->body, $m);

            foreach (array_unique($m[1]) as $url) {
                $this->get($url)->assertOk("{$article->slug} → {$url}");
            }
        }
    }

    public function test_reading_time_is_honest(): void
    {
        $article = Article::first();

        // Довгий розбір не може читатися за хвилину — інакше цифра нікому
        // не допомагає, а лише прикрашає сторінку.
        $this->assertGreaterThanOrEqual(2, $article->reading_minutes);
    }
}
