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
        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->string('name'); // ПІБ
            $table->string('position'); // Посада (кухар, кур'єр тощо)
            $table->decimal('base_rate', 10, 2)->default(0); // Ставка за зміну
            $table->decimal('balance', 10, 2)->default(0); // Поточний борг перед ним (баланс)
            $table->boolean('is_active')->default(true); // Працює чи звільнений
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('employees');
    }
};
