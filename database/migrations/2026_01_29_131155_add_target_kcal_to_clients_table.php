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

            // 1. ORDERS і DISHES (Нові таблиці -> BigInteger)
            // Ми побачили в логах, що Integer для них не підходить
            $table->unsignedBigInteger('order_id');
            $table->unsignedBigInteger('dish_id');
            
            // 2. PRODUCTS (Стара таблиця -> Integer)
            // Ми бачили в першому логу, що BigInteger для неї не підходить
            $table->unsignedInteger('original_product_id'); 
            $table->unsignedInteger('replacement_product_id')->nullable();

            $table->string('comment')->nullable();
            $table->timestamps();

            // --- ЗВ'ЯЗКИ ---
            
            $table->foreign('order_id')->references('id')->on('orders')->cascadeOnDelete();
            $table->foreign('dish_id')->references('id')->on('dishes')->cascadeOnDelete();
            
            $table->foreign('original_product_id')->references('id')->on('products');
            $table->foreign('replacement_product_id')->references('id')->on('products');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_replacements');
    }
};