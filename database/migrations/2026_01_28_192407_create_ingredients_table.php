<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ingredients', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('unit')->default('г');
            $table->decimal('price_per_kg', 8, 2)->nullable();
            
            // КБЖВ
            $table->integer('calories_100g')->default(0);
            $table->integer('proteins_100g')->default(0);
            $table->integer('fats_100g')->default(0);
            $table->integer('carbs_100g')->default(0);
            
            $table->integer('yield_percent')->default(100);
            
            // Склад (чтобы не создавать отдельную миграцию)
            $table->float('stock')->default(0); 

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ingredients');
    }
};