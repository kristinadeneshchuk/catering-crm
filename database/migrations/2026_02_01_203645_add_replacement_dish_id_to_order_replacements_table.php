<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
public function up(): void
{
    Schema::table('order_replacements', function (Blueprint $table) {
        // ID страви, на яку замінили (якщо це заміна страви, а не інгредієнта)
        $table->unsignedBigInteger('replacement_dish_id')->nullable()->after('replacement_product_id');
        $table->foreign('replacement_dish_id')->references('id')->on('dishes')->nullOnDelete();
        
        // Робимо original_product_id nullable, бо при заміні СТРАВИ ми не вказуємо конкретний продукт
        $table->unsignedBigInteger('original_product_id')->nullable()->change();
    });
}

public function down(): void
{
    Schema::table('order_replacements', function (Blueprint $table) {
        $table->dropForeign(['replacement_dish_id']);
        $table->dropColumn('replacement_dish_id');
        $table->unsignedBigInteger('original_product_id')->nullable(false)->change();
    });
}
};
