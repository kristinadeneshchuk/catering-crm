<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_days', function (Blueprint $table) {
            // Час доставки на конкретний день (override order->delivery_time)
            $table->string('delivery_time')->nullable()->after('delivery_comment');
        });
    }

    public function down(): void
    {
        Schema::table('order_days', function (Blueprint $table) {
            $table->dropColumn('delivery_time');
        });
    }
};
