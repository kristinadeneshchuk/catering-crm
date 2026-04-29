<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_channels', function (Blueprint $table) {
            $table->id();

            // Може бути NULL поки не зматчили з існуючим клієнтом
            $table->foreignId('client_id')
                ->nullable()
                ->constrained('clients')
                ->onDelete('cascade');

            // telegram / instagram / viber
            $table->string('channel');

            // ID клієнта в каналі (TG chat_id, IG IGSID, Viber user_id)
            $table->string('external_id');

            // @handle (без @), де є
            $table->string('username')->nullable();

            // Імʼя в каналі (як показується в самому месенджері)
            $table->string('display_name')->nullable();

            // URL аватарки з каналу
            $table->string('avatar_url')->nullable();

            // Додаткова метадата (мова, country, etc.)
            $table->json('raw_meta')->nullable();

            $table->timestamps();

            // Один зовнішній ID на канал — унікальний
            $table->unique(['channel', 'external_id']);
            $table->index('client_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_channels');
    }
};
