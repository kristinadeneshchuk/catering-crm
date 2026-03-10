<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 🔥 Правильна назва таблиці
        Schema::table('daily_menu_dishes', function (Blueprint $table) {
            $table->integer('custom_energy_percent')->nullable()->after('meal_type_id');
        });
    }

    public function down(): void
    {
        Schema::table('daily_menu_dishes', function (Blueprint $table) {
            $table->dropColumn('custom_energy_percent');
        });
    }
};