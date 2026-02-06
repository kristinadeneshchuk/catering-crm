<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('daily_menu_dishes', function (Blueprint $table) {
            // Добавляем ссылку на тип приема пищи (Завтрак, Обед...)
            $table->foreignId('meal_type_id')
                  ->nullable()
                  ->constrained()
                  ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('daily_menu_dishes', function (Blueprint $table) {
            $table->dropForeign(['meal_type_id']);
            $table->dropColumn('meal_type_id');
        });
    }
};