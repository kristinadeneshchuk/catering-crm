<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_ingredient_exclusion', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id');
            $table->unsignedBigInteger('ingredient_id');
            $table->timestamps();

            $table->foreign('order_id')->references('id')->on('orders')->cascadeOnDelete();
            $table->foreign('ingredient_id')->references('id')->on('ingredients')->cascadeOnDelete();

            $table->unique(['order_id', 'ingredient_id'], 'order_ingredient_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_ingredient_exclusion');
    }
};
