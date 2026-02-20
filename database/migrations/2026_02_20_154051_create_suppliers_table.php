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
        Schema::create('suppliers', function (Blueprint $table) {
            $table->id();
            $table->string('name'); // Назва (обов'язкове поле)
            $table->string('inn')->nullable(); // ІПН / ЄДРПОУ
            $table->string('contact_person')->nullable(); // Контактна особа
            $table->string('phone')->nullable(); // Номер телефону
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('suppliers');
    }
};