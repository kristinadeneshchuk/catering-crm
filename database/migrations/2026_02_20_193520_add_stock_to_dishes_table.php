<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dishes', function (Blueprint $table) {
            // Додаємо колонку для залишків напівфабрикатів на складі (за замовчуванням 0)
            $table->decimal('stock', 10, 3)->default(0)->after('base_weight_g');
        });
    }

    public function down(): void
    {
        Schema::table('dishes', function (Blueprint $table) {
            $table->dropColumn('stock');
        });
    }
};