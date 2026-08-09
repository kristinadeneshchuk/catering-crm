<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Поля під імпорт із зовнішніх каталогів.
 *
 * published = false за замовчуванням для всього імпортованого: чужий прайс
 * потрапляє в адмінку на перевірку, а не одразу на вітрину. Менеджер звіряє
 * ціну й опис і публікує вручну.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->boolean('published')->default(true)->after('popularity');
            $table->string('source_url')->nullable()->after('published');
            $table->timestamp('imported_at')->nullable()->after('source_url');
            $table->index('published');
        });

        Schema::table('categories', function (Blueprint $table) {
            $table->string('source_url')->nullable()->after('seo_text');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex(['published']);
            $table->dropColumn(['published', 'source_url', 'imported_at']);
        });

        Schema::table('categories', function (Blueprint $table) {
            $table->dropColumn('source_url');
        });
    }
};
