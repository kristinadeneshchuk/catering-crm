<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Services\Search\ProductSearch;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Перезбирає `products.search_text` для всього каталогу.
 *
 * Потрібна після імпорту, після перейменування бренду чи категорії і взагалі
 * щоразу, коли рядки в базі змінили не через модель.
 */
class ReindexSearch extends Command
{
    protected $signature = 'search:reindex';

    protected $description = 'Перезібрати пошуковий рядок товарів';

    public function handle(ProductSearch $search): int
    {
        $done = 0;

        // Чернетки з імпорту теж індексуються: їх шукає менеджер в адмінці.
        // Оновлюємо через DB, а не save(), щоб не смикати події на кожен рядок.
        Product::withoutGlobalScope('published')
            ->with(['brand', 'category'])
            ->chunkById(200, function ($products) use ($search, &$done) {
                foreach ($products as $product) {
                    DB::table('products')
                        ->where('id', $product->id)
                        ->update(['search_text' => $search->haystack($product)]);
                    $done++;
                }
            });

        $this->info("Проіндексовано товарів: {$done}");

        return self::SUCCESS;
    }
}
