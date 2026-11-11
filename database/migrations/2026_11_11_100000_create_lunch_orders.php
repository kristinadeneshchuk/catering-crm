<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Корпоративні обіди з Lunch Hub: зведення по компаніях на день.
 *
 * Окремі таблиці, а не orders/order_days: обід — не раціон, у нього немає
 * статусів, пауз і днів підписки, і він не має потрапляти в підрахунок раціонів.
 * Лише нові таблиці — наявні дані міграція не чіпає.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lunch_orders', function (Blueprint $t) {
            $t->id();
            $t->date('date');                               // день доставки
            $t->unsignedBigInteger('company_id');           // id компанії в Lunch Hub
            $t->string('company_name');
            $t->unsignedBigInteger('client_id')->nullable(); // клієнт-компанія в CRM, коли привʼязано
            $t->unsignedInteger('sets')->default(0);        // скільки обідів (людей)
            $t->decimal('amount', 10, 2)->default(0);       // сума за день з доплатами
            $t->string('address')->nullable();
            $t->string('delivery_window')->nullable();
            $t->string('contact')->nullable();
            $t->text('comment')->nullable();
            $t->json('people')->nullable();                 // хто що замовив — для наліпок з іменами
            $t->timestamp('received_at')->nullable();
            $t->timestamps();

            $t->unique(['date', 'company_id']);
            $t->index('client_id');
        });

        Schema::create('lunch_order_lines', function (Blueprint $t) {
            $t->id();
            $t->foreignId('lunch_order_id')->constrained('lunch_orders')->cascadeOnDelete();
            $t->unsignedBigInteger('dish_id')->nullable();  // страва CRM; null — власна страва Lunch Hub
            $t->string('name');
            $t->string('kind', 20)->nullable();             // main / light / extra
            $t->unsignedInteger('qty')->default(0);
            $t->unsignedInteger('weight_g')->nullable();    // вага однієї порції
            $t->timestamps();

            $t->index('dish_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lunch_order_lines');
        Schema::dropIfExists('lunch_orders');
    }
};
