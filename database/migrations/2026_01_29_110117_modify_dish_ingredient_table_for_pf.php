<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('dish_ingredients', function (Blueprint $table) {
            $table->foreignId('ingredient_id')->nullable()->change();
            $table->foreignId('child_dish_id')->nullable()->constrained('dishes')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::table('dish_ingredients', function (Blueprint $table) {
            $table->dropForeign(['child_dish_id']);
            $table->dropColumn('child_dish_id');
            $table->foreignId('ingredient_id')->nullable(false)->change();
        });
    }
};
