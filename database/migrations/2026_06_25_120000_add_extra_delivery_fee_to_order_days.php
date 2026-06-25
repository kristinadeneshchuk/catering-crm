<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('order_days', function (Blueprint $table) {
            $table->decimal('extra_delivery_fee', 8, 2)->default(0)->after('delivery_date_override');
        });

        DB::table('settings')->updateOrInsert(
            ['key' => 'far_delivery_fee'],
            ['value' => '150'],
        );
    }

    public function down(): void
    {
        Schema::table('order_days', function (Blueprint $table) {
            $table->dropColumn('extra_delivery_fee');
        });

        DB::table('settings')->where('key', 'far_delivery_fee')->delete();
    }
};
