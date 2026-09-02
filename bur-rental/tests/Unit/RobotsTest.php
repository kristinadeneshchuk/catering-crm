<?php

namespace Tests\Unit;

use App\Services\Import\Robots;
use PHPUnit\Framework\TestCase;

/**
 * Правила чужого сайту. Ходити туди, куди просили не ходити, — найшвидший
 * спосіб отримати бан по IP ще до того, як зібрано перший товар.
 */
class RobotsTest extends TestCase
{
    public function test_site_without_robots_allows_everything(): void
    {
        $this->assertTrue(Robots::allowAll()->allows('https://example.com/ua/anything'));
    }

    public function test_disallow_blocks_a_prefix(): void
    {
        $robots = Robots::parse("User-agent: *\nDisallow: /ua/search\n");

        $this->assertFalse($robots->allows('https://example.com/ua/search?q=perf'));
        $this->assertTrue($robots->allows('https://example.com/ua/perforatory/'));
    }

    public function test_allow_beats_disallow(): void
    {
        $robots = Robots::parse("User-agent: *\nDisallow: /ua/\nAllow: /ua/perforatory/\n");

        $this->assertTrue($robots->allows('https://example.com/ua/perforatory/gbh/'));
        $this->assertFalse($robots->allows('https://example.com/ua/kabinet/'));
    }

    public function test_wildcards_and_end_anchor(): void
    {
        $robots = Robots::parse("User-agent: *\nDisallow: /*/filter/\nDisallow: /print$\n");

        $this->assertFalse($robots->allows('https://example.com/ua/filter/bosch/'));
        $this->assertFalse($robots->allows('https://example.com/print'));
        $this->assertTrue($robots->allows('https://example.com/print/all'));
    }

    public function test_our_own_group_wins_over_the_common_one(): void
    {
        $robots = Robots::parse(
            "User-agent: *\nDisallow: /\n\nUser-agent: BurCatalogResearch/1.0\nDisallow: /ua/cart\n",
            'BurCatalogResearch/1.0'
        );

        // Персональне правило для нас каже «можна все, крім кошика».
        $this->assertTrue($robots->allows('https://example.com/ua/perforatory/'));
        $this->assertFalse($robots->allows('https://example.com/ua/cart'));
    }

    public function test_crawl_delay_is_read_in_milliseconds(): void
    {
        $this->assertSame(1500, Robots::parse("User-agent: *\nCrawl-delay: 1.5\n")->crawlDelayMs);
        $this->assertNull(Robots::parse("User-agent: *\nDisallow: /x\n")->crawlDelayMs);
    }

    public function test_comments_do_not_break_parsing(): void
    {
        $robots = Robots::parse("# коментар\nUser-agent: *   # усім\nDisallow: /ua/search  # пошук\n");

        $this->assertFalse($robots->allows('https://example.com/ua/search'));
    }
}
