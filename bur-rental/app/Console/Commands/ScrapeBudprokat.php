<?php

namespace App\Console\Commands;

use App\Services\Import\BudprokatParser;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * Обхід каталогу budprokat.kiev.ua у JSON-файл.
 *
 * Команда тільки збирає дані — в базу нічого не пише, це робота catalog:import.
 * Запускати треба з машини з відкритим доступом до сайту: пісочниці CI/агентів
 * зазвичай ріжуть такі домени на проксі.
 */
class ScrapeBudprokat extends Command
{
    protected $signature = 'scrape:budprokat
        {--base=https://budprokat.kiev.ua/ua/ : стартова сторінка}
        {--out=import/budprokat.json : куди писати, відносно storage/app}
        {--limit=0 : максимум товарів (0 = без межі)}
        {--delay=400 : пауза між запитами, мс}
        {--dump : зберігати сирий HTML у storage/app/import/html для налагодження селекторів}';

    protected $description = 'Зібрати категорії й товари budprokat.kiev.ua у JSON для catalog:import';

    public function handle(BudprokatParser $parser): int
    {
        $base = rtrim($this->option('base'), '/').'/';
        $limit = (int) $this->option('limit');

        $home = $this->fetch($base);

        if ($home === null) {
            $this->error("Не вдалося відкрити {$base}.");
            $this->line('Якщо ви в пісочниці з проксі — запустіть команду з машини з прямим доступом до сайту.');

            return self::FAILURE;
        }

        $categories = $parser->categories($home, $base);
        $this->info('Категорій знайдено: '.count($categories));

        if ($categories === []) {
            $this->warn('Жодної категорії — верстка змінилася. Запустіть з --dump і поправте BudprokatParser::categories().');
            $this->dump('home', $home);

            return self::FAILURE;
        }

        $products = [];
        $seen = [];

        foreach ($categories as $i => $category) {
            $this->line(sprintf('[%d/%d] %s', $i + 1, count($categories), $category['name']));

            $html = $this->fetch($category['url']);

            if ($html === null) {
                continue;
            }

            $this->dump('cat-'.$i, $html);

            foreach ($parser->productLinks($html, $base) as $link) {
                if (isset($seen[$link])) {
                    continue;
                }

                $seen[$link] = true;

                if ($limit > 0 && count($products) >= $limit) {
                    break 2;
                }

                $page = $this->fetch($link);

                if ($page === null) {
                    continue;
                }

                $product = $parser->product($page, $link);

                // Без назви й ціни це не картка товару, а службова сторінка.
                if ($product['name'] === null || $product['price'] === null) {
                    continue;
                }

                $product['category'] = $category['name'];
                $products[] = $product;

                $this->line('    + '.$product['name'].' — '.$product['price'].' грн/добу');
            }
        }

        $out = $this->option('out');
        Storage::put($out, json_encode(
            ['source' => $base, 'scraped_at' => now()->toIso8601String(), 'categories' => $categories, 'products' => $products],
            JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT,
        ));

        $this->info(sprintf('Готово: %d категорій, %d товарів → storage/app/%s', count($categories), count($products), $out));
        $this->line("Далі: php artisan catalog:import {$out}");

        return self::SUCCESS;
    }

    private function fetch(string $url): ?string
    {
        // Пауза між запитами: ми гість на чужому сервері, а не DDoS.
        usleep((int) $this->option('delay') * 1000);

        try {
            $response = Http::withHeaders([
                'User-Agent' => 'Mozilla/5.0 (compatible; BurCatalogResearch/1.0)',
                'Accept-Language' => 'uk',
            ])->timeout(20)->retry(2, 1000)->get($url);

            return $response->successful() ? $response->body() : null;
        } catch (\Throwable $e) {
            $this->warn("    ! {$url}: {$e->getMessage()}");

            return null;
        }
    }

    private function dump(string $name, string $html): void
    {
        if ($this->option('dump')) {
            Storage::put("import/html/{$name}.html", $html);
        }
    }
}
