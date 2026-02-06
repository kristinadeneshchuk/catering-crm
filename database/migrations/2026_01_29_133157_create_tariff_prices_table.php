<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tariff_prices', function (Blueprint $table) {
            $table->id();
            // Зв'язок з тарифом (напр: "За 1 день", "Від 21 дня")
            $table->foreignId('tariff_id')->constrained()->cascadeOnDelete();
            // Зв'язок з діапазоном калорій
            $table->foreignId('calorie_range_id')->constrained()->cascadeOnDelete();
            // Ціна за 1 день для цієї пари
            $table->decimal('price_per_day', 10, 2); 
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tariff_prices');
    }
};