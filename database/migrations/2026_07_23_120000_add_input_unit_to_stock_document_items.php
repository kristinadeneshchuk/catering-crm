<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_document_items', function (Blueprint $table) {
            // Кількість і одиниця так, як їх ВВЕЛИ з накладної (напр. 10 «кг»),
            // до нормалізації в базову одиницю інгредієнта. Потрібні щоб при
            // редагуванні показати рядок у тому ж вигляді, що на накладній.
            $table->decimal('input_qty', 12, 3)->nullable()->after('qty');
            $table->string('input_unit', 16)->nullable()->after('input_qty');

            // Ціна за базову одиницю для дешевих товарів «за грам» (напр. 12 ₴/кг
            // = 0.012 ₴/г) не влазила у 2 знаки — розширюємо точність.
            $table->decimal('price', 12, 4)->default(0)->change();
        });
    }

    public function down(): void
    {
        Schema::table('stock_document_items', function (Blueprint $table) {
            $table->dropColumn(['input_qty', 'input_unit']);
            $table->decimal('price', 10, 2)->default(0)->change();
        });
    }
};
