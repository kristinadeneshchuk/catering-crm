<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('stock_document_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_document_id')->constrained('stock_documents')->cascadeOnDelete();
            
            // 🔥 Магія Morph: дозволить додавати Інгредієнти, ПФ або Упаковку в одну таблицю
            $table->morphs('itemable'); 
            
            $table->decimal('qty', 10, 3); // Кількість (скільки списуємо/додаємо)
            $table->decimal('price', 10, 2)->default(0); // Ціна за одиницю
            $table->decimal('total_price', 10, 2)->default(0); // Загальна сума рядка
            
            // Поля спеціально для інвентаризації (щоб зберігати історію розбіжностей):
            $table->decimal('system_qty', 10, 3)->nullable(); // Скільки було в програмі
            $table->decimal('difference_qty', 10, 3)->nullable(); // Різниця (нестача/надлишок)
            
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stock_document_items');
    }
};