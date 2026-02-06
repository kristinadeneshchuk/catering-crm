<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_replacements', function (Blueprint $table) {
            $table->id();

            // Всі ваші таблиці (orders, dishes, ingredients) використовують BigInteger
            $table->unsignedBigInteger('order_id');
            $table->unsignedBigInteger('dish_id');
            
            // Ми посилаємось на інгредієнти, але називаємо колонки product_id для зручності
            $table->unsignedBigInteger('original_product_id'); 
            $table->unsignedBigInteger('replacement_product_id')->nullable();

            $table->string('comment')->nullable();
            $table->timestamps();

            // --- ЗВ'ЯЗКИ ---
            $table->foreign('order_id')->references('id')->on('orders')->cascadeOnDelete();
            $table->foreign('dish_id')->references('id')->on('dishes')->cascadeOnDelete();
            
            // ВАЖЛИВО: посилаємось на таблицю 'ingredients'
            $table->foreign('original_product_id')->references('id')->on('ingredients');
            $table->foreign('replacement_product_id')->references('id')->on('ingredients');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_replacements');
    }
};