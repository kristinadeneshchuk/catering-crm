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
        Schema::table('transactions', function (Blueprint $table) {
            // Додаємо зв'язок зі співробітником. 
            // nullable(), тому що звичайні оплати клієнтів не мають співробітника.
            $table->foreignId('employee_id')
                ->nullable()
                ->after('order_id') // Розміщуємо після order_id для порядку
                ->constrained('employees')
                ->onDelete('set null'); // Якщо співробітника видалять, транзакція залишиться
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            // Спочатку видаляємо зовнішній ключ, потім саму колонку
            $table->dropConstrainedForeignId('employee_id');
        });
    }
};