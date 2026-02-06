<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clients', function (Blueprint $table) {
            $table->id();
            
            // Основные данные
            $table->string('name');
            $table->string('phone');
            
            // Эти поля у тебя отсутствовали, поэтому была ошибка:
            $table->text('address')->nullable();
            $table->text('allergies')->nullable();

            // Данные для входа (Личный кабинет)
            $table->string('email')->nullable()->unique();
            $table->string('password')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clients');
    }
};