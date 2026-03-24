<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_addresses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->string('label')->default('Адреса'); // Назва: "Дім", "Робота", тощо
            $table->string('address');
            $table->string('address_entrance')->nullable();
            $table->string('address_apartment')->nullable();
            $table->string('address_floor')->nullable();
            $table->string('delivery_comment')->nullable();
            $table->boolean('is_default')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_addresses');
    }
};
