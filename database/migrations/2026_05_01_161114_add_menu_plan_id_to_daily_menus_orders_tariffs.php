<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1) Створюємо план "Стандарт" з поточних глобальних налаштувань
        $cycleDays    = (int) (DB::table('settings')->where('key', 'menu_cycle_days')->value('value') ?: 24);
        $startDateStr = (string) (DB::table('settings')->where('key', 'menu_cycle_start_date')->value('value') ?: '2025-01-01');

        $defaultPlanId = DB::table('menu_plans')->insertGetId([
            'name'             => 'Стандарт',
            'description'      => 'План створено автоматично при міграції з глобальних налаштувань.',
            'cycle_days'       => $cycleDays,
            'cycle_start_date' => $startDateStr,
            'is_default'       => true,
            'sort_order'       => 0,
            'created_at'       => now(),
            'updated_at'       => now(),
        ]);

        // 2) daily_menus
        Schema::table('daily_menus', function (Blueprint $table) {
            $table->unsignedBigInteger('menu_plan_id')->nullable()->after('id');
            $table->foreign('menu_plan_id')->references('id')->on('menu_plans')->cascadeOnDelete();
        });

        DB::table('daily_menus')->update(['menu_plan_id' => $defaultPlanId]);

        // 3) orders
        Schema::table('orders', function (Blueprint $table) {
            $table->unsignedBigInteger('menu_plan_id')->nullable()->after('menu_type');
            $table->foreign('menu_plan_id')->references('id')->on('menu_plans')->nullOnDelete();
        });

        DB::table('orders')->whereNull('menu_plan_id')->update(['menu_plan_id' => $defaultPlanId]);

        // 4) tariffs — план за замовчуванням, який підставляється у замовлення з цим тарифом
        Schema::table('tariffs', function (Blueprint $table) {
            $table->unsignedBigInteger('default_menu_plan_id')->nullable()->after('id');
            $table->foreign('default_menu_plan_id')->references('id')->on('menu_plans')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('tariffs', function (Blueprint $table) {
            $table->dropForeign(['default_menu_plan_id']);
            $table->dropColumn('default_menu_plan_id');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropForeign(['menu_plan_id']);
            $table->dropColumn('menu_plan_id');
        });

        Schema::table('daily_menus', function (Blueprint $table) {
            $table->dropForeign(['menu_plan_id']);
            $table->dropColumn('menu_plan_id');
        });
    }
};
