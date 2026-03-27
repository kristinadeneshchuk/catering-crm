<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_documents', function (Blueprint $table) {
            $table->boolean('is_paid')->default(false)->after('total_sum');
        });

        // Всі існуючі надходження з транзакціями вважаємо оплаченими
        DB::table('stock_documents')
            ->whereIn('id', function ($q) {
                $q->select('stock_document_id')->from('transactions')->whereNotNull('stock_document_id');
            })
            ->update(['is_paid' => true]);
    }

    public function down(): void
    {
        Schema::table('stock_documents', function (Blueprint $table) {
            $table->dropColumn('is_paid');
        });
    }
};
