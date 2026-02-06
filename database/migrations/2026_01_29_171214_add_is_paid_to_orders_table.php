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