<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->string('category')->nullable()->after('type');
            $table->foreignId('stock_document_id')->nullable()->after('order_id')
                ->constrained('stock_documents')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropForeign(['stock_document_id']);
            $table->dropColumn(['category', 'stock_document_id']);
        });
    }
};
