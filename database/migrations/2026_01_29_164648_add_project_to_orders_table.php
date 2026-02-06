<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Запуск міграції: додаємо маркування бренду до замовлень
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // Додаємо поле проєкту. За замовчуванням ставимо основний бренд.
            $table->string('project')->default('avocado_food')->after('client_id');
        });
    }

    /**
     * Відкат міграції: видаляємо поле проєкту
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('project');
        });
    }
};