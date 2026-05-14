<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('order_days', function (Blueprint $table) {
            $table->date('delivery_date_override')->nullable()->after('delivery_time');
            $table->index(['delivery_date_override']);
        });
    }

    public function down(): void
    {
        Schema::table('order_days', function (Blueprint $table) {
            $table->dropIndex(['delivery_date_override']);
            $table->dropColumn('delivery_date_override');
        });
    }
};
