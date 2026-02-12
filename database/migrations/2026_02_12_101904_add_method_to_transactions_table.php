<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
public function up(): void
{
    Schema::table('transactions', function (Blueprint $table) {
        // Додаємо колонку для методу оплати (cash, card, system...)
        $table->string('method')->default('system')->after('type');
    });
}

public function down(): void
{
    Schema::table('transactions', function (Blueprint $table) {
        $table->dropColumn('method');
    });
}
};
