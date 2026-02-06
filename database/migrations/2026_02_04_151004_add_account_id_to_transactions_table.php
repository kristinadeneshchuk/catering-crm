<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            // Видаляємо старе текстове поле
            $table->dropColumn('method'); 
            
            // Додаємо зв'язок з таблицею рахунків (accounts)
            // Переконайтеся, що ваша таблиця називається 'accounts'
            $table->foreignId('account_id')->nullable()->constrained('accounts')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropForeign(['account_id']);
            $table->dropColumn('account_id');
            $table->string('method')->default('cash');
        });
    }
};
