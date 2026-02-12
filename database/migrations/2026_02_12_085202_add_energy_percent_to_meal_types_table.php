<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
public function up(): void
    {
        // Перевіряємо, чи існує колонка. Якщо НІ — додаємо її.
        if (!Schema::hasColumn('meal_types', 'energy_percent')) {
            Schema::table('meal_types', function (Blueprint $table) {
                $table->integer('energy_percent')->default(0)->after('sort_order');
            });
        }
    }

    public function down(): void
    {
        Schema::table('meal_types', function (Blueprint $table) {
            $table->dropColumn('energy_percent');
        });
    }
};