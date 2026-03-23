<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('daily_menu_items')) {
            return;
        }

        Schema::table('daily_menu_items', function (Blueprint $table) {
            if (!Schema::hasColumn('daily_menu_items', 'custom_energy_percent')) {
                $table->integer('custom_energy_percent')->nullable()->after('meal_type_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('daily_menu_items', function (Blueprint $table) {
            $table->dropColumn('custom_energy_percent');
        });
    }
};