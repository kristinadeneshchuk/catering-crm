<?php

namespace Tests\Unit;

use App\Services\Import\BudprokatParser;
use PHPUnit\Framework\TestCase;

/**
 * Парсер ганяється на фікстурі, а не на живому сайті: тест перевіряє нашу
 * логіку витягання, а не доступність чужого сервера.
 */
class BudprokatParserTest extends TestCase
{
    private BudprokatParser $parser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new BudprokatParser;
    }

    public function test_categories_are_found_and_service_pages_skipped(): void
    {
        $html = <<<'HTML'
        <nav>
            <a href="/ua/arenda-perforatorov/">Оренда перфораторів</a>
            <a href="/ua/arenda-vibroplity-kiev/">Оренда віброплит</a>
            <a href="/ua/ysloviya-arendi.html">Умови оренди</a>
            <a href="/ua/kontakty/">Контакти</a>
            <a href="https://other-site.example/ua/arenda-x/">Чужий сайт</a>
        </nav>
        HTML;

        $categories = $this->parser->categories($html, 'https://budprokat.kiev.ua/ua/');

        $this->assertSame([
            ['name' => 'Оренда перфораторів', 'url' => 'https://budprokat.kiev.ua/ua/arenda-perforatorov/'],
            ['name' => 'Оренда віброплит', 'url' => 'https://budprokat.kiev.ua/ua/arenda-vibroplity-kiev/'],
        ], $categories);
    }

    public function test_product_page_yields_name_price_deposit_and_specs(): void
    {
        $html = <<<'HTML'
        <div class="breadcrumb">
            <a href="/ua/">Головна</a><a href="/ua/arenda-perforatorov/">Перфоратори</a>
        </div>
        <h1>Перфоратор Bosch GBH 2-26</h1>
        <div class="description"><p>Оренда перфоратора для ремонту.</p></div>
        <p>Вартість: 250 грн / добу. Застава 4000 грн.</p>
        <table>
            <tr><td>Потужність</td><td>800 Вт</td></tr>
            <tr><td>Вага</td><td>2,7 кг</td></tr>
        </table>
        HTML;

        $product = $this->parser->product($html, 'https://budprokat.kiev.ua/ua/perforatory/gbh-2-26/');

        $this->assertSame('Перфоратор Bosch GBH 2-26', $product['name']);
        $this->assertSame(250, $product['price']);
        $this->assertSame(4000, $product['deposit']);
        $this->assertSame('Оренда перфоратора для ремонту.', $product['description']);
        $this->assertSame(['Потужність' => '800 Вт', 'Вага' => '2,7 кг'], $product['specs']);
        $this->assertContains('Перфоратори', $product['breadcrumbs']);
    }

    public function test_product_links_ignore_pagination_and_filters(): void
    {
        $html = <<<'HTML'
        <div class="product-list">
            <div class="product"><a href="/ua/perforatory/gbh-2-26/">GBH 2-26</a></div>
            <div class="product"><a href="/ua/perforatory/hr2470/">HR2470</a></div>
            <div class="product"><a href="/ua/perforatory/makita-hm1203/">HM1203</a></div>
            <a href="/ua/perforatory/page/2/">Наступна</a>
            <a href="/ua/catalog/filter/bosch/">Bosch</a>
        </div>
        HTML;

        $links = $this->parser->productLinks($html, 'https://budprokat.kiev.ua/ua/');

        $this->assertSame([
            'https://budprokat.kiev.ua/ua/perforatory/gbh-2-26/',
            'https://budprokat.kiev.ua/ua/perforatory/hr2470/',
            'https://budprokat.kiev.ua/ua/perforatory/makita-hm1203/',
        ], $links);
    }
}
