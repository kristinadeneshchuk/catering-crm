<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('calorie_ranges', function (Blueprint $table) {
            $table->id();
            $table->string('name'); // Напр: "STRONG 2400-2500"
            $table->integer('min_kcal'); // 2400
            $table->integer('max_kcal'); // 2500
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('calorie_ranges');
    }
};