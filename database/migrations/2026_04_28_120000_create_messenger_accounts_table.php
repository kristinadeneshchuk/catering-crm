<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('messenger_accounts', function (Blueprint $table) {
            $table->id();

            // Канал: telegram / instagram / viber
            $table->string('channel');

            // Назва для UI ("TG менеджер 1", "Viber компанії")
            $table->string('display_name');

            // ID нашого акаунта в каналі (phone для TG, page_id для IG, account_id для Viber)
            $table->string('external_account_id')->nullable();

            // Зашифровані креденшелі (token / session string / page access token)
            $table->text('credentials')->nullable();

            // active / inactive / expired / error
            $table->string('status')->default('inactive');

            // Текст останньої помилки (для UI)
            $table->text('last_error')->nullable();

            $table->timestamp('last_synced_at')->nullable();

            $table->foreignId('connected_by_user_id')
                ->nullable()
                ->constrained('users')
                ->onDelete('set null');

            $table->timestamps();

            $table->index(['channel', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('messenger_accounts');
    }
};
