<?php

namespace App\Console\Commands;

use App\Services\Import\BudprokatParser;
use App\Services\Import\Robots;
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
        {--dump : зберігати сирий HTML у storage/app/import/html для налагодження селекторів}
        {--probe= : прочитати одну сторінку і показати, що знайшли селектори}';

    protected $description = 'Зібрати категорії й товари budprokat.kiev.ua у JSON для catalog:import';

    /**
     * Представляємося чесно. Маскуватися під браузер, щоб обійти обмеження, —
     * це вже не дослідження ринку, а те, за що банять по справі.
     */
    private const AGENT = 'BurCatalogResearch/1.0 (+https://bur.ua/about-crawler)';

    private Robots $robots;

    public function handle(BudprokatParser $parser): int
    {
        $base = rtrim($this->option('base'), '/').'/';
        $limit = (int) $this->option('limit');

        $this->robots = $this->robots($base);

        if ($probe = $this->option('probe')) {
            return $this->probe($parser, $probe, $base);
        }

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

    /**
     * Правила чужого сайту. Ходити туди, куди просили не ходити, — це і
     * неввічливо, і найшвидший спосіб отримати бан по IP.
     */
    private function robots(string $base): Robots
    {
        $url = rtrim($base, '/');
        $url = preg_replace('~^(https?://[^/]+).*$~', '$1', $url).'/robots.txt';

        try {
            $response = Http::withHeaders(['User-Agent' => self::AGENT])->timeout(10)->get($url);
        } catch (\Throwable) {
            $this->warn('robots.txt не прочитався — вважаємо, що обхід дозволений.');

            return Robots::allowAll();
        }

        if (! $response->successful()) {
            return Robots::allowAll();
        }

        $robots = Robots::parse($response->body(), self::AGENT);

        if ($robots->crawlDelayMs) {
            $this->line("robots.txt просить паузу {$robots->crawlDelayMs} мс — виконуємо.");
        }

        return $robots;
    }

    /**
     * Одна сторінка й розбір по кісточках.
     *
     * Селектори написані за евристиками, і перший прогін на живому сайті
     * майже напевно щось не знайде. Замість гри в «збережи HTML, відкрий
     * редактор, здогадайся» — показуємо, що саме зловив кожен селектор.
     */
    private function probe(BudprokatParser $parser, string $url, string $base): int
    {
        $html = $this->fetch($url);

        if ($html === null) {
            $this->error("Сторінка не відкрилася: {$url}");

            return self::FAILURE;
        }

        $this->dump('probe', $html);
        $this->line('HTML: '.number_format(strlen($html)).' байт, збережено в storage/app/import/html/probe.html');
        $this->newLine();

        $categories = $parser->categories($html, $base);
        $this->result('categories()', count($categories), array_column($categories, 'name'));

        $links = $parser->productLinks($html, $base);
        $this->result('productLinks()', count($links), $links);

        $product = $parser->product($html, $url);
        $this->newLine();
        $this->line('<options=bold>product()</>');

        foreach (['name', 'price', 'deposit', 'brand', 'category'] as $field) {
            $value = $product[$field] ?? null;
            $this->line(sprintf(
                '  %s %-9s %s',
                $value === null ? '<fg=red>✗</>' : '<fg=green>✓</>',
                $field,
                $value === null ? '<fg=gray>не знайдено</>' : (string) $value,
            ));
        }

        $this->line('  <fg=gray>·</> характеристик: '.count($product['specs'] ?? []));

        return self::SUCCESS;
    }

    /** @param list<string> $items */
    private function result(string $what, int $count, array $items): void
    {
        $this->line("<options=bold>{$what}</> — знайдено {$count}");

        foreach (array_slice($items, 0, 5) as $item) {
            $this->line('  · '.$item);
        }

        if ($count === 0) {
            $this->warn('  порожньо: селектор більше не збігається з версткою');
        }
    }

    private function fetch(string $url): ?string
    {
        if (isset($this->robots) && ! $this->robots->allows($url)) {
            $this->warn("    robots.txt забороняє {$url} — пропускаємо");

            return null;
        }

        // Пауза між запитами: ми гість на чужому сервері, а не DDoS.
        usleep(max((int) $this->option('delay'), $this->robots->crawlDelayMs ?? 0) * 1000);

        try {
            $response = Http::withHeaders([
                'User-Agent' => self::AGENT,
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
