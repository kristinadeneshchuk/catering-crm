<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Перехід від календарних дат до номерів днів циклу.
     */
    public function up(): void
    {
        Schema::table('daily_menus', function (Blueprint $table) {
            // Додаємо номер дня (наприклад, від 1 до 30)
            if (!Schema::hasColumn('daily_menus', 'day_number')) {
                $table->integer('day_number')->after('id')->unsigned()->nullable();
            }
            
            // Видаляємо колонку з датою, вона нам більше не потрібна
            if (Schema::hasColumn('daily_menus', 'date')) {
                $table->dropColumn('date');
            }
        });
    }

    /**
     * Відкат змін: повертаємо дату та видаляємо номер дня.
     */
    public function down(): void
    {
        Schema::table('daily_menus', function (Blueprint $table) {
            $table->date('date')->after('id')->nullable();
            $table->dropColumn('day_number');
        });
    }
};