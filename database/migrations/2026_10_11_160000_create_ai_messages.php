<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Чат з агентом: його повідомлення з файлами, відповіді людей, картки пропозицій. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_messages', function (Blueprint $t) {
            $t->id();
            $t->foreignId('ai_agent_id')->constrained('ai_agents')->cascadeOnDelete();
            $t->string('direction', 8);                    // agent / human / system
            // Агент → інший агент (рецензія чернетки): у Telegram не йде, видно в CRM.
            $t->foreignId('to_agent_id')->nullable()->constrained('ai_agents')->nullOnDelete();
            $t->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->text('text')->nullable();
            $t->json('attachments')->nullable();          // [{name, mime, path, size}]
            $t->foreignId('ai_proposal_id')->nullable()->constrained('ai_proposals')->nullOnDelete();
            $t->json('telegram_messages')->nullable();    // [{chat_id, message_id}] — для реплаїв
            $t->timestamps();
            $t->index(['ai_agent_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_messages');
    }
};
