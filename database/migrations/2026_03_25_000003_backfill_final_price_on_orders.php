<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Для всіх існуючих замовлень без знижки — final_price = total_price
        DB::table('orders')
            ->where('final_price', 0)
            ->where('total_price', '>', 0)
            ->update([
                'final_price'     => DB::raw('total_price'),
                'discount_amount' => 0,
            ]);
    }

    public function down(): void
    {
        //
    }
};
