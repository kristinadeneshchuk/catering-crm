<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Статті під інформаційні запити.
 *
 * «Що потрібно для стяжки» шукають за півкроку до оренди: людина ще не знає,
 * який інструмент їй треба, і саме тому готова прочитати. Кожна стаття веде у
 * вже готовий комплект під задачу — це не блог заради блогу, а верхня частина
 * тієї самої воронки.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('articles', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('title');
            $table->string('excerpt', 400);          // анонс у списку і meta description
            $table->text('body');                    // markdown
            // Куди вести читача далі. Стаття без цього — просто текст.
            $table->foreignId('kit_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('category_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('published')->default(true);
            $table->date('published_at')->nullable();
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('articles');
    }
};
