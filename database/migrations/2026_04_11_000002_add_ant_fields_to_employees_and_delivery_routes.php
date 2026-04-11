<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Додаємо ant_driver_name до employees — ім'я як воно приходить з АНТ
        Schema::table('employees', function (Blueprint $table) {
            $table->string('ant_driver_name')->nullable()->after('name')
                ->comment('Ім\'я водія в ANT Logistics (для автоматичного зв\'язку з маршрутами)');
        });

        // Додаємо employee_id до delivery_routes
        Schema::table('delivery_routes', function (Blueprint $table) {
            $table->foreignId('employee_id')->nullable()->after('driver_name')
                ->constrained('employees')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('delivery_routes', function (Blueprint $table) {
            $table->dropForeign(['employee_id']);
            $table->dropColumn('employee_id');
        });

        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn('ant_driver_name');
        });
    }
};
