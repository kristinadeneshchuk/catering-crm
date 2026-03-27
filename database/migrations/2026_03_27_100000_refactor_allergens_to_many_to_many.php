<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Pivot-таблиця: інгредієнт може мати багато алергенів
        Schema::create('allergen_ingredient', function (Blueprint $table) {
            $table->unsignedBigInteger('allergen_id');
            $table->unsignedBigInteger('ingredient_id');

            $table->primary(['allergen_id', 'ingredient_id']);
            $table->foreign('allergen_id')->references('id')->on('allergens')->cascadeOnDelete();
            $table->foreign('ingredient_id')->references('id')->on('ingredients')->cascadeOnDelete();
        });

        // Переносимо існуючі дані з allergen_id у pivot
        if (Schema::hasColumn('ingredients', 'allergen_id')) {
            $rows = \DB::table('ingredients')->whereNotNull('allergen_id')->get(['id', 'allergen_id']);
            foreach ($rows as $row) {
                \DB::table('allergen_ingredient')->insertOrIgnore([
                    'allergen_id'   => $row->allergen_id,
                    'ingredient_id' => $row->id,
                ]);
            }

            // Прибираємо старе поле
            Schema::table('ingredients', function (Blueprint $table) {
                $table->dropForeign(['allergen_id']);
                $table->dropColumn('allergen_id');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('allergen_ingredient');

        Schema::table('ingredients', function (Blueprint $table) {
            $table->unsignedBigInteger('allergen_id')->nullable()->after('group');
            $table->foreign('allergen_id')->references('id')->on('allergens')->nullOnDelete();
        });
    }
};
