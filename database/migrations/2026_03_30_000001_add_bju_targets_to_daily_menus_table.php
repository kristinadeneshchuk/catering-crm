<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('daily_menus', function (Blueprint $table) {
            $table->unsignedSmallInteger('target_protein_g')->default(113)->after('target_kcal');
            $table->unsignedSmallInteger('target_fat_g')->default(50)->after('target_protein_g');
            $table->unsignedSmallInteger('target_carb_g')->default(150)->after('target_fat_g');
        });

        // Розраховуємо дефолти для існуючих записів на основі target_kcal
        // Білки: 30% ккал / 4, Жири: 30% ккал / 9, Вуглеводи: 40% ккал / 4
        DB::statement('UPDATE daily_menus SET
            target_protein_g = ROUND(target_kcal * 0.30 / 4),
            target_fat_g     = ROUND(target_kcal * 0.30 / 9),
            target_carb_g    = ROUND(target_kcal * 0.40 / 4)
        ');
    }

    public function down(): void
    {
        Schema::table('daily_menus', function (Blueprint $table) {
            $table->dropColumn(['target_protein_g', 'target_fat_g', 'target_carb_g']);
        });
    }
};
