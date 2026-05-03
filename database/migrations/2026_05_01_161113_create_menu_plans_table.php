<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('menu_plans', function (Blueprint $table) {
            $table->id();
            $table->string('name');                                    // напр. "Стандарт", "Преміум", "Веган"
            $table->text('description')->nullable();
            $table->unsignedInteger('cycle_days')->default(24);        // довжина циклу
            $table->date('cycle_start_date');                          // якірна дата
            $table->boolean('is_default')->default(false);             // план за замовчуванням для нових замовлень
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index('is_default');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('menu_plans');
    }
};
