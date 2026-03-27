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
        Schema::table('daily_menus', function (Blueprint $table) {
            $table->decimal('cached_cost_950',  8, 2)->nullable()->after('target_kcal');
            $table->decimal('cached_cost_1500', 8, 2)->nullable()->after('cached_cost_950');
            $table->decimal('cached_cost_2500', 8, 2)->nullable()->after('cached_cost_1500');
        });
    }

    public function down(): void
    {
        Schema::table('daily_menus', function (Blueprint $table) {
            $table->dropColumn(['cached_cost_950', 'cached_cost_1500', 'cached_cost_2500']);
        });
    }
};
