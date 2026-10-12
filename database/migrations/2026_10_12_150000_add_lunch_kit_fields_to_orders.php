<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Корпоративні обіди: одне замовлення на N однакових комплектів (кухня й
 * фасування множать, доставка — одна точка), порції «як у повному раціоні»
 * (частина дня, страви щодня обираємо з меню) і доставка окремим курʼєром
 * поза маршрутом ANT.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $t) {
            $t->unsignedSmallInteger('kit_count')->default(1)->after('calories');
            $t->boolean('partial_day')->default(false)->after('kit_count');
            $t->boolean('separate_courier')->default(false)->after('partial_day');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $t) {
            $t->dropColumn(['kit_count', 'partial_day', 'separate_courier']);
        });
    }
};
