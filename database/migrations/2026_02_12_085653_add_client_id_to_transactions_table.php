<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
public function up(): void
{
    Schema::table('transactions', function (Blueprint $table) {
        // Додаємо колонку клієнта
        $table->foreignId('client_id')->nullable()->after('id')->constrained()->cascadeOnDelete();

        // Робимо order_id необов'язковим (щоб можна було просто поповнювати баланс)
        $table->unsignedBigInteger('order_id')->nullable()->change();
    });

    // (Опціонально) Якщо у вас вже є транзакції, цей SQL запит заповнить client_id на основі замовлень
    // \DB::statement('UPDATE transactions t JOIN orders o ON t.order_id = o.id SET t.client_id = o.client_id');
}

public function down(): void
{
    Schema::table('transactions', function (Blueprint $table) {
        $table->dropForeign(['client_id']);
        $table->dropColumn('client_id');
        $table->unsignedBigInteger('order_id')->nullable(false)->change();
    });
}
};
