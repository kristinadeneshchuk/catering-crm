<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Події з Inbox (вебхук, unified-inbox/docs/events-api.md): дедуп за event_id і журнал,
 * що агент «Переписки» вже обробив. Тексту повідомлень тут немає — лише ідентифікатори;
 * сам чат агент читає з Inbox на час задачі.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inbox_events', function (Blueprint $t) {
            $t->id();
            $t->string('event_id', 64)->unique();
            $t->string('type', 40);
            $t->unsignedBigInteger('conversation_id')->nullable();
            $t->unsignedBigInteger('message_id')->nullable();
            $t->string('direction', 8)->nullable();         // in / out
            $t->string('sender_type', 16)->nullable();      // client / manager / telegram / scheduled
            $t->unsignedBigInteger('crm_client_id')->nullable();
            $t->string('contact_kind', 16)->nullable();     // client / courier / supplier / staff / spam
            $t->string('project_code', 32)->nullable();
            $t->timestamp('occurred_at')->nullable();
            $t->string('status', 16)->default('new');       // new / queued / skipped / processed
            $t->string('note')->nullable();                 // чому пропущено
            $t->timestamps();
            $t->index(['conversation_id', 'id']);
            $t->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inbox_events');
    }
};
