<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Каталог. Ключова відмінність від звичайного магазину — ціна не одна:
 * у товару тарифна сходинка, і чим довше строк, тим дешевше день.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('brands', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('name');
            $table->string('country')->nullable();
            $table->string('accent_color', 7)->nullable(); // фірмовий колір інструменту
            $table->text('about')->nullable();
            $table->text('why')->nullable();
            $table->timestamps();
        });

        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('categories')->cascadeOnDelete();
            $table->string('slug')->unique();
            $table->string('name');
            $table->string('name_genitive')->nullable(); // «Оренда перфораторів»
            $table->text('lead')->nullable();
            $table->text('seo_text')->nullable();
            $table->json('filter_specs')->nullable();    // техпараметри саме цієї категорії
            $table->unsignedSmallInteger('products_count')->default(0);
            $table->unsignedSmallInteger('position')->default(0);
            $table->boolean('heavy')->default(false);    // важка техніка — тільки доставка
            $table->timestamps();
        });

        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->constrained()->cascadeOnDelete();
            $table->foreignId('brand_id')->constrained()->cascadeOnDelete();
            $table->string('slug')->unique();
            $table->string('name');
            $table->string('sku')->unique();
            $table->text('lead')->nullable();
            $table->text('description')->nullable();
            $table->json('specs')->nullable();          // назва => значення
            $table->json('key_specs')->nullable();      // 2–3 в картці лістингу
            $table->json('kit')->nullable();            // що входить у комплект
            $table->json('not_included')->nullable();
            $table->unsignedInteger('deposit');         // застава, повертається
            $table->unsignedInteger('base_price');      // ціна за день на базовому тарифі
            $table->unsignedInteger('retail_price')->nullable(); // скільки коштує купити
            $table->decimal('weight_kg', 6, 2)->nullable();
            $table->decimal('rating', 2, 1)->default(0);
            $table->unsignedSmallInteger('reviews_count')->default(0);
            $table->unsignedSmallInteger('popularity')->default(0);
            $table->string('manual_url')->nullable();
            $table->string('video_url')->nullable();
            $table->text('seo_text')->nullable();
            $table->timestamps();
        });

        Schema::create('tariff_tiers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('label');                     // 3–6 днів
            $table->unsignedSmallInteger('min_days');
            $table->unsignedSmallInteger('max_days')->nullable();
            $table->unsignedInteger('price');
            $table->string('note')->nullable();          // −17%
            $table->timestamps();
        });

        Schema::create('extras', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->nullable()->constrained()->nullOnDelete();
            $table->string('slug')->unique();
            $table->string('name');
            $table->string('sub')->nullable();           // «купівля, шт»
            $table->unsignedInteger('price');
            $table->timestamps();
        });

        // Витратники, які пропонуються саме до цієї моделі.
        Schema::create('extra_product', function (Blueprint $table) {
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('extra_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position')->default(0);
            $table->primary(['product_id', 'extra_id']);
        });

        // «З цим орендують» — задається редактором, а не вигадується на льоту.
        Schema::create('product_related', function (Blueprint $table) {
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('related_id')->constrained('products')->cascadeOnDelete();
            $table->string('kind')->default('with');     // with | similar
            $table->unsignedSmallInteger('position')->default(0);
            $table->primary(['product_id', 'related_id', 'kind']);
        });

        Schema::create('kits', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('name');                      // Кладу плитку
            $table->string('task');                      // формулювання задачі
            $table->text('lead')->nullable();
            $table->text('guide')->nullable();
            $table->string('guide_url')->nullable();
            $table->unsignedSmallInteger('discount_percent')->default(0);
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
        });

        Schema::create('kit_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kit_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('why')->nullable();           // навіщо саме ця позиція
            $table->boolean('optional')->default(false);
            $table->unsignedSmallInteger('position')->default(0);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kit_items');
        Schema::dropIfExists('kits');
        Schema::dropIfExists('product_related');
        Schema::dropIfExists('extra_product');
        Schema::dropIfExists('extras');
        Schema::dropIfExists('tariff_tiers');
        Schema::dropIfExists('products');
        Schema::dropIfExists('categories');
        Schema::dropIfExists('brands');
    }
};
