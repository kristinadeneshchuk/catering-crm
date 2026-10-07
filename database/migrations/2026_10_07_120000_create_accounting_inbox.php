<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Документи з Telegram-групи «Бухгалтерія»: накладні й квитанції про оплату.
 *
 * Кожне фото/PDF (або альбом) — один запис: що це, що з нього зчитано і що
 * вирішила людина. Так бот памʼятає, що вже бачив (дублі), і не питає двічі.
 * Номер і дата з бланка накладної — окремими полями, щоб ловити повторне
 * внесення тієї самої накладної.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounting_items', function (Blueprint $t) {
            $t->id();
            $t->string('chat_id', 32);
            $t->unsignedBigInteger('message_id');
            $t->string('media_group_id', 64)->nullable();
            $t->json('files');                         // [{path, unique_id, mime}]
            $t->string('kind', 16)->nullable();        // invoice | payment | other
            $t->json('extracted')->nullable();
            $t->string('status', 24)->default('new');  // див. App\Models\AccountingItem
            $t->unsignedBigInteger('bot_message_id')->nullable();
            $t->foreignId('stock_document_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('transaction_id')->nullable()->constrained()->nullOnDelete();
            $t->string('decided_by', 32)->nullable();
            $t->timestamp('decided_at')->nullable();
            $t->timestamps();

            $t->index(['chat_id', 'media_group_id']);
            $t->index('status');
        });

        Schema::table('stock_documents', function (Blueprint $t) {
            $t->string('invoice_number', 64)->nullable()->after('comment');
            $t->date('invoice_date')->nullable()->after('invoice_number');
        });
    }

    public function down(): void
    {
        Schema::table('stock_documents', function (Blueprint $t) {
            $t->dropColumn(['invoice_number', 'invoice_date']);
        });
        Schema::dropIfExists('accounting_items');
    }
};
