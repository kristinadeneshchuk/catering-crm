<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_day_dishes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->foreignId('meal_type_id')->constrained();
            $table->foreignId('dish_id')->constrained();
            $table->unsignedSmallInteger('weight_grams')->nullable()->comment('null = рахується автоматично по калоріям');
            $table->timestamps();

            $table->unique(['order_id', 'date', 'meal_type_id'], 'order_day_meal_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_day_dishes');
    }
};
