<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Само меню (Просто дата)
        Schema::create('daily_menus', function (Blueprint $table) {
            $table->id();
            $table->date('date')->unique(); // На один день - одно меню
            $table->timestamps();
        });

        // 2. Связь: Какие блюда входят в это меню
        Schema::create('daily_menu_dishes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('daily_menu_id')->constrained()->cascadeOnDelete();
            $table->foreignId('dish_id')->constrained()->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_menu_dishes');
        Schema::dropIfExists('daily_menus');
    }
};