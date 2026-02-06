<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tariffs', function (Blueprint $table) {
            // Видаляємо стару колонку ціни, бо тепер ціни в матриці
            $table->dropColumn('price_per_day');
        });
    }

    public function down(): void
    {
        Schema::table('tariffs', function (Blueprint $table) {
            // Якщо захочемо повернути назад
            $table->decimal('price_per_day', 10, 2)->nullable();
        });
    }
};