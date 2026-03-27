<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('allergens', function (Blueprint $table) {
            $table->id();
            $table->string('name'); // напр. "Глютен", "Лактоза", "Горіхи"
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('allergens');
    }
};
