<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_days', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete(); // Прив'язка до замовлення
            $table->date('date'); // Конкретна дата харчування
            $table->boolean('is_completed')->default(false); // Чи вже пройшов цей день (для історії)
            $table->timestamps();

            // Забороняємо дублювання: не можна додати одну й ту ж дату двічі в одне замовлення
            $table->unique(['order_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_days');
    }
};