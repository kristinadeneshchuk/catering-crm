<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('courier_mileage_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->unsignedInteger('start_km')->nullable();
            $table->unsignedInteger('end_km')->nullable();
            $table->decimal('fuel_uah', 10, 2)->default(0);
            // Снапшот ставки амортизації на момент створення/редагування.
            // Якщо менеджер змінить amort_per_km у налаштуваннях — старі логи
            // (і відповідні нарахування в balance) лишаються консистентними.
            $table->decimal('amort_per_km', 8, 2)->default(1);
            $table->timestamps();

            $table->unique(['employee_id', 'date']);
            $table->index('date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('courier_mileage_logs');
    }
};
