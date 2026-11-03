<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Звідки взявся маршрут: ANT (порожньо) чи наш RouteLogix ('routelogix').
 *
 * Рядки RouteLogix синк з ANT не перезаписує й не чистить: ANT лишається
 * джерелом для змін, які ще не перевели на RouteLogix.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('order_days', 'route_source')) {
            Schema::table('order_days', function (Blueprint $table) {
                $table->string('route_source', 20)->nullable()->after('ant_driver');
            });
        }

        if (! Schema::hasColumn('delivery_routes', 'source')) {
            Schema::table('delivery_routes', function (Blueprint $table) {
                $table->string('source', 20)->nullable()->after('ant_route_num');
            });
        }
    }

    public function down(): void
    {
        Schema::table('order_days', function (Blueprint $table) {
            $table->dropColumn('route_source');
        });

        Schema::table('delivery_routes', function (Blueprint $table) {
            $table->dropColumn('source');
        });
    }
};
