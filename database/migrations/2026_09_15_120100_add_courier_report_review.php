<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Звіт курʼєра — чернетка на перевірку адміном; ЗП погоджується в CRM з
     * вибором рахунку і йде повідомленням у чат оплат (docs/tz-ops-agent.md §1, §5).
     *
     * Нові колонки мають безпечні значення: наявні звіти й виплати не змінюються.
     */
    public function up(): void
    {
        // draft (на перевірці) → accepted / rejected
        Schema::table('courier_shift_reports', function (Blueprint $table) {
            $table->string('source', 16)->default('bot')->after('status'); // bot | inbox
            $table->unsignedBigInteger('inbox_conversation_id')->nullable()->after('tg_chat_id');
            $table->json('anomalies')->nullable()->after('problems');
            $table->text('ai_comment')->nullable()->after('anomalies');
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('reject_reason', 500)->nullable();
        });

        Schema::table('courier_payouts', function (Blueprint $table) {
            $table->unsignedBigInteger('account_id')->nullable()->after('to_pay');
            $table->unsignedBigInteger('transaction_id')->nullable()->after('account_id');
            $table->decimal('paid_amount', 10, 2)->nullable()->after('transaction_id');
            $table->json('payment_message')->nullable(); // {chat_id, message_id}
        });

        // Картка для виплат (шифрується в моделі) і чат курʼєра в Inbox.
        Schema::table('employees', function (Blueprint $table) {
            $table->text('payout_card')->nullable();
            $table->unsignedBigInteger('inbox_conversation_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('employees', fn (Blueprint $t) => $t->dropColumn(['payout_card', 'inbox_conversation_id']));

        Schema::table('courier_payouts', fn (Blueprint $t) => $t->dropColumn([
            'account_id', 'transaction_id', 'paid_amount', 'payment_message',
        ]));

        Schema::table('courier_shift_reports', fn (Blueprint $t) => $t->dropColumn([
            'source', 'inbox_conversation_id', 'anomalies', 'ai_comment', 'reviewed_by', 'reviewed_at', 'reject_reason',
        ]));
    }
};
