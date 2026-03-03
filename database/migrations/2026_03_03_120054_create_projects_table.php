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
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->string('name'); // Публічна назва (напр., "Avocado Food")
            $table->string('slug')->unique(); // Системна назва (напр., "avocado_food" або "u_fit") - дуже важливо для старих даних!
            $table->string('logo')->nullable(); // Шлях до картинки логотипу
            $table->string('color')->default('primary'); // Колір для бейджів (success, warning, danger, info тощо)
            $table->boolean('is_active')->default(true); // Чи працює зараз цей бізнес
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('projects');
    }
};
