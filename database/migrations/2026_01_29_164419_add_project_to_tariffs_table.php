<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Запуск міграції: додаємо поле проекту для розділення брендів
     */
    public function up(): void
    {
        Schema::table('tariffs', function (Blueprint $table) {
            /** * Додаємо поле project. 
             * За замовчуванням ставимо 'avocado_food', оскільки це ваш основний бізнес.
             */
            $table->string('project')->default('avocado_food')->after('name');
        });
    }

    /**
     * Відкат міграції: видаляємо поле проекту
     */
    public function down(): void
    {
        Schema::table('tariffs', function (Blueprint $table) {
            $table->dropColumn('project');
        });
    }
};