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
        Schema::table('orders', function (Blueprint $table) {
            // Додаємо поле для типу розкладу (після поля status, якщо воно є, або просто в кінець)
            $table->string('schedule_type')->nullable()->after('status');
            
            // Додаємо поле для часу доставки
            $table->string('delivery_time')->nullable()->after('schedule_type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // Видаляємо поля, якщо відкочуємо міграцію
            $table->dropColumn(['schedule_type', 'delivery_time']);
        });
    }
};