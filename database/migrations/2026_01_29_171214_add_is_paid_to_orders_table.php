<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Додаємо реальний прапорець оплати в базу даних
     */
    public function up(): void
    {
        // Колонку вже створює 2026_01_28_200132_create_orders_table — без перевірки
        // migrate падає на свіжій базі ("duplicate column is_paid").
        if (Schema::hasColumn('orders', 'is_paid')) {
            return;
        }

        Schema::table('orders', function (Blueprint $table) {
            // Створюємо логічне поле (true/false) після ціни замовлення
            $table->boolean('is_paid')->default(false)->after('total_price');
        });
    }

    /**
     * Відкат міграції: видаляємо поле
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('is_paid');
        });
    }
};