<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Одометр у кур'єра може бути у км або милях (напр., імпортні авто).
     * Зберігаємо одиницю на працівнику (default) + знімок на логу
     * (щоб зміна налаштування не змінювала історичні розрахунки).
     *
     * Розрахунок: якщо unit=mi → km = (end - start) × 1.6.
     */
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->string('mileage_unit', 2)->default('km')->after('fuel_consumption');
        });

        Schema::table('courier_mileage_logs', function (Blueprint $table) {
            $table->string('mileage_unit', 2)->default('km')->after('fuel_consumption');
        });
    }

    public function down(): void
    {
        Schema::table('courier_mileage_logs', function (Blueprint $table) {
            $table->dropColumn('mileage_unit');
        });
        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn('mileage_unit');
        });
    }
};
