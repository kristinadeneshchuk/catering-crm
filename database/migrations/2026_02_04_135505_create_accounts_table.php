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
        Schema::create('accounts', function (Blueprint $table) {
            $table->id();
            
            // Назва рахунку (напр. "Готівка", "Розрахунковий рахунок")
            $table->string('name'); 
            
            // Тип рахунку (зберігаємо ключі: 'cash', 'online', 'card')
            $table->string('type')->default('cash'); 
            
            // Баланс: 15 цифр загалом, 2 після коми (для копійок). За замовчуванням 0.
            $table->decimal('balance', 15, 2)->default(0); 
            
            // Чи це основний рахунок (false за замовчуванням)
            $table->boolean('is_default')->default(false);
            
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('accounts');
    }
};