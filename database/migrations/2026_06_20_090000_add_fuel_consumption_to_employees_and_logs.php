<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Витрата пального у машини кур'єра (літрів на 100 км).
        // CRM сама рахує спалене пальне за день, менеджер вводить лише
        // початковий/кінцевий одометр і ціну літра.
        Schema::table('employees', function (Blueprint $table) {
            $table->decimal('fuel_consumption', 5, 2)->nullable()->after('balance');
        });

        // courier_mileage_logs: 'fuel_uah' тепер означає 'fuel_price_per_liter' —
        // ціну літра у день логу. Перейменування зберігає старі рядки (їх
        // зараз лише 1 з нулями, тож семантичної катастрофи нема).
        Schema::table('courier_mileage_logs', function (Blueprint $table) {
            $table->renameColumn('fuel_uah', 'fuel_price_per_liter');
        });

        // Snapshot витрати на момент створення логу — щоб після зміни
        // характеристик машини історичні нарахування лишались консистентні.
        Schema::table('courier_mileage_logs', function (Blueprint $table) {
            $table->decimal('fuel_consumption', 5, 2)->nullable()->after('fuel_price_per_liter');
        });
    }

    public function down(): void
    {
        Schema::table('courier_mileage_logs', function (Blueprint $table) {
            $table->dropColumn('fuel_consumption');
        });
        Schema::table('courier_mileage_logs', function (Blueprint $table) {
            $table->renameColumn('fuel_price_per_liter', 'fuel_uah');
        });
        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn('fuel_consumption');
        });
    }
};
