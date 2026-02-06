<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Запуск міграції: створюємо структуру для персоналізації клієнтів
     */
    public function up(): void
    {
        // 1. Оновлюємо основну таблицю клієнтів
        Schema::table('clients', function (Blueprint $table) {
            // Додаємо Email, якщо його ще немає поруч з телефоном
            if (!Schema::hasColumn('clients', 'email')) {
                $table->string('email')->nullable()->after('phone');
            }
            
            // Поле для маркетингової аналітики
            $table->string('sales_source')->nullable()->after('email');
            
            // Поле для детальних інструкцій кухні
            $table->text('production_comment')->nullable()->after('address');
        });

        // 2. Створюємо таблицю-зв'язок для виключень по продуктах (інгредієнтах)
        Schema::create('client_ingredient_exclusion', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ingredient_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
        });

        // 3. Створюємо таблицю-зв'язок для виключень по конкретних стравах
        Schema::create('client_dish_exclusion', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('dish_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Відкат міграції: видаляємо все у зворотному порядку
     */
    public function down(): void
    {
        // Спочатку видаляємо таблиці зв'язків
        Schema::dropIfExists('client_dish_exclusion');
        Schema::dropIfExists('client_ingredient_exclusion');

        // Потім видаляємо додані колонки з основної таблиці
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn(['email', 'sales_source', 'production_comment']);
        });
    }
};