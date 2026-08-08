<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Наявність по датах і філіях. Це головна перевага сервісу, тому таблиця
 * мусить бути правдивою: кешувати її агресивно не можна.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('qty')->default(1);
            $table->timestamps();
            $table->unique(['product_id', 'branch_id']);
        });

        // Один рядок = один зайнятий день конкретного екземпляра у філії.
        Schema::create('unavailable_dates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->string('reason')->default('rented'); // rented | service
            $table->timestamps();
            $table->unique(['product_id', 'branch_id', 'date']);
            $table->index(['product_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('unavailable_dates');
        Schema::dropIfExists('inventory');
    }
};
