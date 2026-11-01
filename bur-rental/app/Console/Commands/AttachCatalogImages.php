<?php

namespace App\Console\Commands;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/*
 | Прив'язує залиті файли products/<slug>.webp до товарів, а категоріям дає фото
 | їхнього першого товару. Вже задане фото не чіпає: те, що менеджер поставив
 | в адмінці, важливіше за файл на диску. Запускати можна скільки завгодно разів.
 */
class AttachCatalogImages extends Command
{
    protected $signature = 'catalog:attach-images';

    protected $description = 'Прив\'язати файли фото до товарів і категорій без фото';

    public function handle(): int
    {
        $disk = Storage::disk('public');
        $products = 0;
        $categories = 0;

        Product::withoutGlobalScope('published')->whereNull('image')->each(function (Product $product) use ($disk, &$products) {
            $path = "products/{$product->slug}.webp";

            if ($disk->exists($path)) {
                $product->update(['image' => $path]);
                $products++;
            }
        });

        Category::whereNull('image')->orderBy('id')->each(function (Category $category) use (&$categories) {
            $ids = Category::where('parent_id', $category->id)->pluck('id')->push($category->id);
            $image = Product::whereIn('category_id', $ids)->whereNotNull('image')->orderBy('id')->value('image');

            if ($image) {
                $category->update(['image' => $image]);
                $categories++;
            }
        });

        $this->info("Товарів: {$products}, категорій: {$categories}");

        return self::SUCCESS;
    }
}
