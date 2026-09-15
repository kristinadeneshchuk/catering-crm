<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Чернетки накладних і списань (docs/tz-ops-agent.md §3–4).
     *
     * Колонка status є з лютого (усі документи — 'completed'). Нове значення
     * 'draft': документ видно в CRM, але він не рухає ні залишків, ні середньої
     * ціни інгредієнта, ні каси, поки адмін його не проведе.
     */
    public function up(): void
    {
        Schema::table('stock_documents', function (Blueprint $table) {
            $table->string('source', 16)->default('manual')->after('status'); // manual | ai
            $table->text('ai_comment')->nullable();
            $table->json('attachments')->nullable(); // фото накладної, голосове
            $table->timestamp('posted_at')->nullable();
            $table->unsignedBigInteger('posted_by')->nullable();
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::table('stock_documents', function (Blueprint $table) {
            $table->dropIndex(['status']);
            $table->dropColumn(['source', 'ai_comment', 'attachments', 'posted_at', 'posted_by']);
        });
    }
};
