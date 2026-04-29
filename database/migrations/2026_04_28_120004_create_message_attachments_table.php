<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('message_attachments', function (Blueprint $table) {
            $table->id();

            $table->foreignId('message_id')
                ->constrained('messages')
                ->onDelete('cascade');

            // Шлях у Storage (заповнюється після завантаження)
            $table->string('file_path')->nullable();

            // Оригінальний URL з каналу (поки не завантажили)
            $table->string('file_url', 1024)->nullable();

            $table->string('file_name')->nullable();
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();

            $table->string('thumbnail_path')->nullable();

            // Для аудіо / відео
            $table->unsignedInteger('duration_seconds')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('message_attachments');
    }
};
