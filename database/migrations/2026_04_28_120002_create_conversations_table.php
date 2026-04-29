<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversations', function (Blueprint $table) {
            $table->id();

            // З ким говоримо (клієнт у конкретному каналі)
            $table->foreignId('client_channel_id')
                ->constrained('client_channels')
                ->onDelete('cascade');

            // З якого нашого акаунта ведеться діалог
            $table->foreignId('messenger_account_id')
                ->constrained('messenger_accounts')
                ->onDelete('cascade');

            // Денормалізований канал — для швидких фільтрів у списку
            $table->string('channel');

            // ID треду в каналі (для TG = client_channel.external_id; для IG це conversation thread)
            $table->string('external_chat_id');

            // Менеджер, який веде діалог
            $table->foreignId('assigned_user_id')
                ->nullable()
                ->constrained('users')
                ->onDelete('set null');

            // open / closed
            $table->string('status')->default('open');

            $table->timestamp('last_message_at')->nullable();
            $table->string('last_message_preview', 255)->nullable();
            $table->unsignedInteger('unread_count')->default(0);

            $table->timestamp('closed_at')->nullable();

            $table->timestamps();

            $table->unique(['messenger_account_id', 'client_channel_id']);
            $table->index(['status', 'last_message_at']);
            $table->index('assigned_user_id');
            $table->index(['channel', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversations');
    }
};
