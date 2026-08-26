<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Знімок точок маршруту: хто куди виїжджав.
     *
     * Досі точка існувала лише як набір полів на order_days (ant_route_num,
     * ant_route_id, ant_route_pos, ant_driver). Це не архів, а мітка на живому
     * рядку замовлення, і вона зникала трьома способами: замовлення видалили,
     * замовлення пішло зі статусу active/new (7195 з 7780 точок уже там —
     * collectOrderDaysForDelivery їх не бачить), маршрут прибрали при
     * перебудові в ANT.
     *
     * Тут точка живе окремо і денормалізовано: курʼєр, авто, телефон і адреса
     * скопійовані на момент виїзду. Навіть якщо потім зміняться і замовлення, і
     * маршрут, і картка клієнта — запис лишиться тим, чим був.
     *
     * ВАЖЛИВО: таблиця тільки на запис із ANT і на читання в CRM. У зворотну
     * синхронізацію з ANT вона не входить ніколи — саме тому розійтись даним
     * нема де: ANT лишається єдиним джерелом для живого, а це архів.
     */
    public function up(): void
    {
        Schema::create('route_stops', function (Blueprint $table) {
            $table->id();

            // Дата ДОСТАВКИ (не дата їжі) і реальна зміна маршруту.
            $table->date('date');
            $table->string('shift', 16)->nullable();

            // Маршрут. delivery_route_id — зручність для звітів; без каскаду,
            // бо знімок має пережити видалення маршруту.
            $table->unsignedBigInteger('delivery_route_id')->nullable();
            $table->string('ant_route_id')->nullable();
            $table->unsignedInteger('ant_route_num')->nullable();
            $table->unsignedInteger('position')->nullable();

            // Курʼєр на момент виїзду.
            $table->unsignedBigInteger('employee_id')->nullable();
            $table->string('driver_name')->nullable();
            $table->string('courier_name')->nullable();
            $table->string('courier_phone', 32)->nullable();
            $table->string('car_number', 32)->nullable();

            // Клієнт на момент виїзду.
            $table->unsignedBigInteger('client_id')->nullable();
            $table->string('client_name')->nullable();
            $table->string('client_phone', 32)->nullable();
            $table->text('address')->nullable();

            $table->unsignedBigInteger('order_id')->nullable();
            $table->unsignedBigInteger('order_day_id')->nullable();

            // Звідки рядок: 'ant' — прийшов з вивантаження, 'backfill' —
            // відновлений з order_days заднім числом.
            $table->string('source', 16)->default('ant');

            $table->timestamps();

            // Одна точка = один клієнт на одному маршруті. Подвійний раціон
            // (кілька order_days у того самого клієнта) — це одна поїздка.
            $table->unique(['date', 'ant_route_id', 'client_id'], 'route_stops_unique_stop');

            $table->index(['date', 'shift']);
            $table->index('client_id');
            $table->index('employee_id');
            $table->index('order_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('route_stops');
    }
};
