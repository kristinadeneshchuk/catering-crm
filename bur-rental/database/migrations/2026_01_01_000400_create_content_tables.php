<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Контент, який висить на різних сутностях: відгуки і FAQ.
 * Полиморфні прив'язки — щоб той самий блок працював на товарі, категорії,
 * філії та місті без трьох копій коду.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reviews', function (Blueprint $table) {
            $table->id();
            $table->morphs('reviewable');            // product | branch | city
            $table->string('author');
            $table->string('author_note')->nullable(); // «Прораб, ТОВ Моноліт-Буд»
            $table->unsignedTinyInteger('rating');
            $table->text('body');
            $table->string('source')->default('site'); // site | google
            $table->date('published_at');
            $table->timestamps();
        });

        Schema::create('faqs', function (Blueprint $table) {
            $table->id();
            $table->nullableMorphs('faqable');       // null = загальний FAQ
            $table->string('scope')->nullable();     // rental | delivery | return | b2b
            $table->string('question');
            $table->text('answer');
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('faqs');
        Schema::dropIfExists('reviews');
    }
};
