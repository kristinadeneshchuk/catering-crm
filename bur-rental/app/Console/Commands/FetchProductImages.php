<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Services\ProductImageSources;
use Illuminate\Console\Command;
use Throwable;

/**
 * Фото з офіційних сайтів виробників за списком у
 * database/seeders/data/product-image-sources.php (slug → посилання).
 *
 * Запускається на сервері під час деплою: там є інтернет, якого немає в
 * хмарній сесії. Товари, у яких фото вже є, не чіпає — ні ті, що завантажив
 * менеджер, ні ті, що підтягнулись раніше. Чого не знайшлося — показує
 * списком, їх догружають руками в адмінці (Товари → Фото).
 */
class FetchProductImages extends Command
{
    protected $signature = 'products:fetch-images {--slug=* : Тільки ці товари} {--list= : Інший файл зі списком джерел}';

    protected $description = 'Підтягнути фото товарів з офіційних сайтів виробників (тільки товарам без фото)';

    public function handle(ProductImageSources $sources): int
    {
        $file = $this->option('list') ?: database_path('seeders/data/product-image-sources.php');
        $list = require $file;
        $only = $this->option('slug');

        $products = Product::withoutGlobalScopes()
            ->when($only, fn ($q) => $q->whereIn('slug', $only))
            ->orderBy('id')
            ->get();

        $rows = [];

        foreach ($products as $product) {
            if ($product->images->isNotEmpty()) {
                $rows[] = [$product->slug, '—', 'фото вже є'];

                continue;
            }

            $urls = $list[$product->slug] ?? [];

            if (! $urls) {
                $rows[] = [$product->slug, '—', '✗ немає посилань у списку'];

                continue;
            }

            $errors = [];

            foreach ($urls as $url) {
                try {
                    $sources->import($product, $url);
                    $rows[] = [$product->slug, $url, '✓'];

                    continue 2;
                } catch (Throwable $e) {
                    $errors[] = $e->getMessage();
                }
            }

            $rows[] = [$product->slug, $urls[0], '✗ '.mb_substr(implode('; ', $errors), 0, 120)];
        }

        $this->table(['Товар', 'Джерело', 'Результат'], $rows);

        $missing = Product::withoutGlobalScopes()->doesntHave('images')->count();
        $missing
            ? $this->warn("Без фото лишилось товарів: {$missing}. Їх можна догрузити в адмінці: Товари → Фото.")
            : $this->info('Фото є в усіх товарів.');

        return self::SUCCESS;
    }
}
