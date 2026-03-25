<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->enum('discount_type', ['percent', 'fixed'])->nullable()->after('total_price');
            $table->decimal('discount_value', 10, 2)->nullable()->after('discount_type');
            $table->text('discount_reason')->nullable()->after('discount_value');
            $table->decimal('discount_amount', 10, 2)->default(0)->after('discount_reason');
            $table->decimal('final_price', 10, 2)->default(0)->after('discount_amount');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['discount_type', 'discount_value', 'discount_reason', 'discount_amount', 'final_price']);
        });
    }
};
