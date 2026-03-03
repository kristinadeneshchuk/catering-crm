<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_calls', function (Blueprint $table) {
            $table->id();
            
            // Зв'язки
            $table->foreignId('order_id')->constrained()->onDelete('cascade');
            $table->foreignId('client_id')->constrained()->onDelete('cascade');
            
            // Статус на Канбан-дошці (new, no_answer, thinking, refused, success)
            $table->string('status')->default('new'); 
            
            // Дані про розмову
            $table->text('comment')->nullable();
            $table->datetime('next_call_at')->nullable(); // Перетелефонувати...
            $table->string('refusal_reason')->nullable(); // Причина відмови (якщо status = refused)
            
            // Хто з менеджерів веде картку
            $table->foreignId('user_id')->nullable()->constrained()->onDelete('set null');
            
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_calls');
    }
};