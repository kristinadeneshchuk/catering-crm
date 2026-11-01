<?php

namespace App\Console\Commands;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/*
 | Заводить залиті файли products/<slug>.webp у галерею товарів без фото, а
 | категоріям дає фото їхнього першого товару. Товар, у якого фото вже є (з
 | адмінки чи products:fetch-images), не чіпає. Запускати можна скільки завгодно разів.
 */
class AttachCatalogImages extends Command
{
    protected $signature = 'catalog:attach-images';

    protected $description = 'Прив\'язати файли фото до товарів і категорій без фото';

    public function handle(): int
    {
        $disk = Storage::disk('public');
        $sources = $this->sourcePages();
        $products = 0;
        $categories = 0;

        Product::withoutGlobalScope('published')->orderBy('id')->each(function (Product $product) use ($disk, $sources, &$products) {
            $path = "products/{$product->slug}.webp";

            if ($product->images->isEmpty() && $disk->exists($path)) {
                $product->images()->create(['path' => $path, 'position' => 0, 'source_url' => $sources[$product->slug] ?? null]);
                $products++;
            }
        });

        Category::whereNull('image')->orderBy('id')->each(function (Category $category) use (&$categories) {
            $ids = Category::where('parent_id', $category->id)->pluck('id')->push($category->id);
            $image = Product::whereIn('category_id', $ids)->whereHas('images')->orderBy('id')->first()?->images->first()->path;

            if ($image) {
                $category->update(['image' => $image]);
                $categories++;
            }
        });

        $this->info("Товарів: {$products}, категорій: {$categories}");

        return self::SUCCESS;
    }

    /** Звідки взяте кожне фото (slug → сторінка-джерело), щоб завжди можна було показати походження. */
    private function sourcePages(): array
    {
        $file = database_path('seeders/data/product-image-sources.json');

        if (! is_file($file)) {
            return [];
        }

        return collect(json_decode(file_get_contents($file), true) ?: [])
            ->pluck('source_page', 'slug')
            ->filter()
            ->all();
    }
}
