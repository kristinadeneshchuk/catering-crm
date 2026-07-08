<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Розділяємо зміну на дві частини: ранок / вечір.
     * shift_slot = 'full' — сумісність зі старою поведінкою (одна зміна на день).
     * 'morning' / 'evening' — новий режим двох виїздів у день (курʼєр з двома пробігами).
     */
    public function up(): void
    {
        Schema::table('employee_shifts', function (Blueprint $table) {
            $table->string('shift_slot', 10)->default('full')->after('date');
        });

        Schema::table('courier_mileage_logs', function (Blueprint $table) {
            $table->string('shift_slot', 10)->default('full')->after('date');
        });

        // Розширюємо унікальний ключ, щоб можна було 2 записи на день (ранок+вечір).
        Schema::table('employee_shifts', function (Blueprint $table) {
            // employee_shifts у 2026_03_16 не мав явного unique — тільки логіка. Створюємо новий.
            $table->unique(['employee_id', 'date', 'shift_slot'], 'employee_shifts_emp_date_slot_unique');
        });

        Schema::table('courier_mileage_logs', function (Blueprint $table) {
            $table->dropUnique(['employee_id', 'date']);
            $table->unique(['employee_id', 'date', 'shift_slot'], 'courier_mileage_logs_emp_date_slot_unique');
        });
    }

    public function down(): void
    {
        Schema::table('courier_mileage_logs', function (Blueprint $table) {
            $table->dropUnique('courier_mileage_logs_emp_date_slot_unique');
            $table->unique(['employee_id', 'date']);
            $table->dropColumn('shift_slot');
        });

        Schema::table('employee_shifts', function (Blueprint $table) {
            $table->dropUnique('employee_shifts_emp_date_slot_unique');
            $table->dropColumn('shift_slot');
        });
    }
};
