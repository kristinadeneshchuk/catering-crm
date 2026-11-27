<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Шаблон замін уміє міняти не лише продукт, а й страву цілком
 * («Спорт»: десерт → білковий перекус). Рядок шаблону — або пара продуктів,
 * або пара страв.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('replacement_bundle_items', function (Blueprint $table) {
            $table->unsignedBigInteger('original_ingredient_id')->nullable()->change();
            $table->unsignedBigInteger('replacement_ingredient_id')->nullable()->change();

            $table->unsignedBigInteger('original_dish_id')->nullable()->after('replacement_ingredient_id');
            $table->unsignedBigInteger('replacement_dish_id')->nullable()->after('original_dish_id');

            $table->foreign('original_dish_id')->references('id')->on('dishes')->cascadeOnDelete();
            $table->foreign('replacement_dish_id')->references('id')->on('dishes')->cascadeOnDelete();
            $table->unique(['bundle_id', 'original_dish_id']);
        });
    }

    public function down(): void
    {
        Schema::table('replacement_bundle_items', function (Blueprint $table) {
            $table->dropForeign(['original_dish_id']);
            $table->dropForeign(['replacement_dish_id']);
            $table->dropUnique(['bundle_id', 'original_dish_id']);
            $table->dropColumn(['original_dish_id', 'replacement_dish_id']);
        });
    }
};
