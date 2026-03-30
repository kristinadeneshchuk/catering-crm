<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('menu_token', 32)->nullable()->unique()->after('comment');
        });

        // Generate tokens for all existing orders
        DB::table('orders')->whereNull('menu_token')->orderBy('id')->get()->each(function ($order) {
            DB::table('orders')
                ->where('id', $order->id)
                ->update(['menu_token' => bin2hex(random_bytes(16))]);
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('menu_token');
        });
    }
};
