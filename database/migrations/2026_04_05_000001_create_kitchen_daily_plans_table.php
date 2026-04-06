<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kitchen_daily_plans', function (Blueprint $table) {
            $table->id();
            $table->date('date')->unique();
            $table->json('plan_json')->nullable();
            $table->string('generated_by')->nullable(); // ім'я користувача
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kitchen_daily_plans');
    }
};
