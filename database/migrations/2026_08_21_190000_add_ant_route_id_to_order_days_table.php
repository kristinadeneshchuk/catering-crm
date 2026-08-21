<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Номер маршруту ANT (ant_route_num) перенумеровується при кожній перебудові
     * маршрутів, тож зв'язка «день ↔ маршрут» на ньому розсипалась: доплати за
     * дальню доставку губились, щойно логіст перегравав розклад. Route_Id — 
     * стабільний ключ ANT, зберігаємо його поруч.
     */
    public function up(): void
    {
        Schema::table('order_days', function (Blueprint $table) {
            $table->string('ant_route_id', 32)->nullable()->after('ant_route_num');
        });
    }

    public function down(): void
    {
        Schema::table('order_days', function (Blueprint $table) {
            $table->dropColumn('ant_route_id');
        });
    }
};
