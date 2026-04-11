<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delivery_routes', function (Blueprint $table) {
            $table->id();
            $table->date('date');
            $table->string('shift', 20)->default('all'); // morning / evening / all

            // Дані з АНТ
            $table->integer('ant_route_id')->nullable();
            $table->integer('ant_route_num')->nullable();
            $table->string('driver_name')->nullable();
            $table->string('auto_name')->nullable();
            $table->string('model_auto')->nullable();
            $table->string('registration_number')->nullable();

            // Маршрут — статистика
            $table->integer('count_comps')->default(0);     // кількість точок
            $table->double('distance_calc')->nullable();     // км план
            $table->double('distance_fact')->nullable();     // км факт
            $table->double('fuel_city')->nullable();         // витрата палива (л)
            $table->string('route_time_b')->nullable();      // час виїзду
            $table->string('route_time_e')->nullable();      // час повернення

            // Вартість
            $table->decimal('ant_cost_route', 10, 2)->default(0);    // вартість від АНТ
            $table->decimal('calculated_cost', 10, 2)->default(0);   // наш розрахунок (ставка кур'єра)

            $table->timestamps();

            $table->unique(['date', 'ant_route_id']);
            $table->index('date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_routes');
    }
};
