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
            $table->decimal('price_per_day', 10, 2)->nullable()->after('total_price');
        });

        // Backfill: розрахувати price_per_day для існуючих замовлень
        // total_price / duration (якщо duration > 0)
        DB::statement('
            UPDATE orders
            SET price_per_day = CASE
                WHEN duration > 0 THEN ROUND(total_price / duration, 2)
                ELSE NULL
            END
            WHERE price_per_day IS NULL AND total_price > 0
        ');
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('price_per_day');
        });
    }
};
