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
            // Додаємо колонку type, за замовчуванням це буде 'product'
            $table->string('type')->default('product')->after('dish_id');
        });
    }

    public function down(): void
    {
        Schema::table('dish_ingredients', function (Blueprint $table) {
            $table->dropColumn('type');
        });
    }
};
