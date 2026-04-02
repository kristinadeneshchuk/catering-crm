<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_days', function (Blueprint $table) {
            $table->unsignedSmallInteger('ant_route_num')->nullable()->after('delivery_comment');
            $table->unsignedSmallInteger('ant_route_pos')->nullable()->after('ant_route_num');
            $table->string('ant_driver', 100)->nullable()->after('ant_route_pos');
            $table->string('ant_delivery_group', 100)->nullable()->after('ant_driver');
        });
    }

    public function down(): void
    {
        Schema::table('order_days', function (Blueprint $table) {
            $table->dropColumn(['ant_route_num', 'ant_route_pos', 'ant_driver', 'ant_delivery_group']);
        });
    }
};
