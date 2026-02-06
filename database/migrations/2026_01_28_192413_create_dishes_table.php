<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Таблица самих блюд [cite: 59]
        Schema::create('dishes', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            // Вес при базовой калорийности 1800 ккал [cite: 20]
            $table->integer('base_weight_g')->default(0); 
            $table->timestamps();
        });

        // 2. Таблица Техкарты (из чего состоит блюдо) [cite: 71]
        Schema::create('dish_ingredients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dish_id')->constrained()->cascadeOnDelete(); // Ссылка на блюдо
            $table->foreignId('ingredient_id')->constrained()->cascadeOnDelete(); // Ссылка на ингредиент
            
            // Сколько грамм этого ингредиента идет в Базовое блюдо (1800 ккал) [cite: 78]
            $table->float('net_weight_g'); 
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dish_ingredients');
        Schema::dropIfExists('dishes');
    }
};