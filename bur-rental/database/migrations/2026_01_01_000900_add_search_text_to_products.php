<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Один рядок на товар, у якому зібрано все, за чим його шукають: назва,
 * артикул, бренд, категорія — і те саме в транслітерації.
 *
 * Індексу тут немає свідомо: пошук іде через `LIKE '%…%'`, а такий шаблон
 * B-tree не використовує в жодній із двох баз. Колонка існує заради того, щоб
 * не робити три JOIN'и і не гадати, у якому саме полі лежить збіг.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->text('search_text')->nullable()->after('seo_text');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('search_text');
        });
    }
};
