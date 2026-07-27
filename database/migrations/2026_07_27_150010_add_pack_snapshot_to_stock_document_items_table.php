<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_document_items', function (Blueprint $table) {
            // Снапшот «як бачить бухгалтер»: скільки упаковок і по чому.
            // qty / price / total_price / input_qty лишаються джерелом істини для
            // складу (базова одиниця). Ці поля — лише щоб відновити рядок у
            // «упаковочному» вигляді при редагуванні накладної.
            $table->decimal('pack_count', 10, 3)->nullable()->after('input_unit'); // к-сть упаковок
            $table->decimal('pack_price', 12, 4)->nullable()->after('pack_count');  // ціна за упаковку
        });
    }

    public function down(): void
    {
        Schema::table('stock_document_items', function (Blueprint $table) {
            $table->dropColumn(['pack_count', 'pack_price']);
        });
    }
};
