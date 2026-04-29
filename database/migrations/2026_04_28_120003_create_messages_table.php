<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('messages', function (Blueprint $table) {
            $table->id();

            $table->foreignId('conversation_id')
                ->constrained('conversations')
                ->onDelete('cascade');

            // inbound / outbound
            $table->string('direction');

            // client / user / system
            $table->string('sender_type');

            // Хто з менеджерів написав (якщо direction=outbound і sender_type=user)
            $table->foreignId('sender_user_id')
                ->nullable()
                ->constrained('users')
                ->onDelete('set null');

            // text / image / video / audio / document / sticker / location / system
            $table->string('type')->default('text');

            $table->text('text')->nullable();

            // ID повідомлення в каналі (для відстеження статусів і дедуплікації)
            $table->string('external_message_id')->nullable();

            // Цитоване повідомлення (self FK)
            $table->foreignId('reply_to_message_id')
                ->nullable()
                ->constrained('messages')
                ->onDelete('set null');

            // pending / sent / delivered / read / failed
            $table->string('status')->default('sent');

            $table->text('error_message')->nullable();

            // Оригінальний payload з каналу (для дебагу і відновлення)
            $table->json('raw_payload')->nullable();

            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('read_at')->nullable();

            $table->timestamps();

            $table->index(['conversation_id', 'created_at']);
            $table->index('external_message_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('messages');
    }
};
