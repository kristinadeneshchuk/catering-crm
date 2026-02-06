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
        Schema::table('clients', function (Blueprint $table) {
            // Додаємо поле коментаря для доставки
            // Якщо у вас немає поля 'address', приберіть ->after('address')
            $table->text('delivery_comment')->nullable()->after('address');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            // Видаляємо поле при відкочуванні
            $table->dropColumn('delivery_comment');
        });
    }
};