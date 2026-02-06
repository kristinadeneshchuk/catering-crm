<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            
            // Связи
            $table->foreignId('client_id')->constrained()->cascadeOnDelete(); // Кто заказал
            $table->foreignId('tariff_id')->constrained(); // Какой тариф
            
            // Параметры питания
            $table->integer('calories'); // Введенная калорийность (напр. 1500)
            $table->float('scale_factor'); // Рассчитанный Scale (0.833) [cite: 106]
            
            // Даты и Деньги
            $table->date('start_date'); // Когда начать есть
            $table->date('end_date'); // Когда закончить (рассчитаем сами)
            
            $table->decimal('total_price', 10, 2); // Итоговая сумма
            $table->boolean('is_paid')->default(false); // Оплачено или нет
            
            $table->string('status')->default('new'); // Статус (new, active, done)
            $table->text('comment')->nullable(); // Комментарий к доставке
            
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};