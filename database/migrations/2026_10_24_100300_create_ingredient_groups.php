<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Групи-синоніми продуктів для виключень: «кукурудза» = зерно, крохмаль,
 * «Овочі мексиканські»; «горох» = горошок, мікрозелень гороху, маш тощо.
 * Виключили групу — заборонені всі її продукти. Раніше це ловили регекси й хибили
 * («курка» ловила «яйце куряче»). Наповнення — командою agents:ingredient-groups з dry-run.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ingredient_groups', function (Blueprint $t) {
            $t->id();
            $t->string('slug', 48)->unique();
            $t->string('name');
            $t->text('note')->nullable();
            $t->timestamps();
        });

        Schema::create('ingredient_group_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('ingredient_group_id')->constrained('ingredient_groups')->cascadeOnDelete();
            $t->foreignId('ingredient_id')->constrained('ingredients')->cascadeOnDelete();
            $t->unique(['ingredient_group_id', 'ingredient_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ingredient_group_items');
        Schema::dropIfExists('ingredient_groups');
    }
};
