<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_days', function (Blueprint $table) {
            $table->text('address')->nullable()->after('is_completed');
            $table->string('address_entrance')->nullable()->after('address');
            $table->string('address_apartment')->nullable()->after('address_entrance');
            $table->string('address_floor')->nullable()->after('address_apartment');
            $table->text('delivery_comment')->nullable()->after('address_floor');
        });
    }

    public function down(): void
    {
        Schema::table('order_days', function (Blueprint $table) {
            $table->dropColumn(['address', 'address_entrance', 'address_apartment', 'address_floor', 'delivery_comment']);
        });
    }
};
