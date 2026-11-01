<?php

use App\Models\Product;
use App\Models\ProductImage;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 | Дві гілки зробили фото по-різному: одне поле products.image (так залиті
 | 42 фото на сервері) і галерея product_images з WebP-варіантами. Лишаємо
 | галерею: переносимо в неї те, що було в полі, і поле прибираємо.
 | У категорій поле image лишається — їм галерея не потрібна.
 */
return new class extends Migration
{
    public function up(): void
    {
        $sources = $this->sourcePages();

        Product::withoutGlobalScopes()->whereNotNull('image')->orderBy('id')->each(function (Product $product) use ($sources) {
            $source = $sources[$product->slug] ?? null;

            if ($product->images()->doesntExist()) {
                // Через модель, а не DB::insert — вона ж наріже WebP-варіанти.
                $product->images()->create(['path' => $product->image, 'position' => 0, 'source_url' => $source]);
            } elseif ($source) {
                // Той самий файл уже завів catalog:attach-images — лише допишемо, звідки він.
                $product->images()->where('path', $product->image)->whereNull('source_url')->update(['source_url' => $source]);
            }
        });

        Schema::table('products', fn (Blueprint $table) => $table->dropColumn('image'));
    }

    public function down(): void
    {
        Schema::table('products', fn (Blueprint $table) => $table->string('image')->nullable());

        ProductImage::where('position', 0)->each(
            fn (ProductImage $image) => Product::withoutGlobalScopes()->whereKey($image->product_id)->update(['image' => $image->path])
        );
    }

    /** Звідки взяте кожне фото: slug → сторінка виробника чи дистриб'ютора. */
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
};
