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
        // Ідемпотентно: перевіряємо кожен крок — щоб можна було догнати міграцію
        // навіть якщо частина уже застосована руками або впала на середині.
        if (! Schema::hasColumn('employee_shifts', 'shift_slot')) {
            Schema::table('employee_shifts', function (Blueprint $table) {
                $table->string('shift_slot', 10)->default('full')->after('date');
            });
        }

        if (! Schema::hasColumn('courier_mileage_logs', 'shift_slot')) {
            Schema::table('courier_mileage_logs', function (Blueprint $table) {
                $table->string('shift_slot', 10)->default('full')->after('date');
            });
        }

        if (! $this->indexExists('employee_shifts', 'employee_shifts_emp_date_slot_unique')) {
            Schema::table('employee_shifts', function (Blueprint $table) {
                $table->unique(['employee_id', 'date', 'shift_slot'], 'employee_shifts_emp_date_slot_unique');
            });
        }

        // Порядок важливий: спочатку створюємо новий unique (він покриє FK
        // employee_id), і тільки потім знімаємо старий — інакше MySQL не дозволить
        // дропнути індекс "потрібний для foreign key".
        if (! $this->indexExists('courier_mileage_logs', 'courier_mileage_logs_emp_date_slot_unique')) {
            Schema::table('courier_mileage_logs', function (Blueprint $table) {
                $table->unique(['employee_id', 'date', 'shift_slot'], 'courier_mileage_logs_emp_date_slot_unique');
            });
        }

        if ($this->indexExists('courier_mileage_logs', 'courier_mileage_logs_employee_id_date_unique')) {
            Schema::table('courier_mileage_logs', function (Blueprint $table) {
                $table->dropUnique('courier_mileage_logs_employee_id_date_unique');
            });
        }
    }

    private function indexExists(string $table, string $index): bool
    {
        $conn = Schema::getConnection();
        $db   = $conn->getDatabaseName();
        return (int) $conn->selectOne(
            'SELECT COUNT(1) AS c FROM information_schema.statistics WHERE table_schema = ? AND table_name = ? AND index_name = ?',
            [$db, $table, $index]
        )->c > 0;
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
