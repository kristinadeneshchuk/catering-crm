<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
public function up(): void
    {
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete(); // Прив'язка до замовлення
            $table->decimal('amount', 10, 2); // Сума
            $table->string('method')->default('cash'); // Готівка, картка
            $table->string('type')->default('income'); // income (надходження) або refund (повернення)
            $table->date('date')->default(now()); // Дата оплати
            $table->text('comment')->nullable();
            $table->foreignId('user_id')->nullable(); // Хто створив (менеджер)
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
