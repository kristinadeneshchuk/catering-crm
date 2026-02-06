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
        // 1. Додаємо поле % енергетичної цінності до таблиці типів прийомів їжі
        // Перевіряємо, чи існує таблиця, щоб уникнути помилок
        if (Schema::hasTable('meal_types')) {
            Schema::table('meal_types', function (Blueprint $table) {
                // Додаємо колонку energy_percent (ціле число)
                // Ставимо default(0), щоб старі записи не викликали помилку.
                // Після міграції ви зможете заповнити їх через адмінку.
                $table->integer('energy_percent')->default(0)->after('sort_order');
            });
        }

        // 2. Створюємо проміжну таблицю для зв'язку "Багато до Багатьох" (Клієнт <-> Прийом їжі)
        Schema::create('client_meal_type', function (Blueprint $table) {
            $table->id();

            // Зв'язок з клієнтом (якщо клієнта видалять - запис тут теж зникне)
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            
            // Зв'язок з типом прийому їжі (якщо тип видалять - запис тут теж зникне)
            $table->foreignId('meal_type_id')->constrained('meal_types')->cascadeOnDelete();

            // Запобігаємо дублюванню (щоб не можна було додати "Сніданок" клієнту двічі)
            $table->unique(['client_id', 'meal_type_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // 1. Спочатку видаляємо таблицю зв'язку, бо вона залежить від meal_types
        Schema::dropIfExists('client_meal_type');

        // 2. Видаляємо колонку з meal_types
        if (Schema::hasTable('meal_types')) {
            Schema::table('meal_types', function (Blueprint $table) {
                $table->dropColumn('energy_percent');
            });
        }
    }
};