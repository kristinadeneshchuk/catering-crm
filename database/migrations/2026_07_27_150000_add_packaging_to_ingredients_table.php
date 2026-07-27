<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ingredients', function (Blueprint $table) {
            // «Продається упаковками»: закупник вводить к-сть упаковок і ціну за
            // упаковку, а вага упаковки береться з картки. Все нормалізується в
            // базову одиницю інгредієнта (kg/l/pcs) існуючим механізмом input_unit.
            $table->boolean('is_packaged')->default(false)->after('unit');

            // Вміст однієї упаковки у власній одиниці package_unit (напр. 400 г).
            $table->decimal('package_weight', 10, 3)->nullable()->after('is_packaged');

            // Одиниця вмісту упаковки — сумісна з базовою (г/мл/шт).
            $table->string('package_unit', 8)->nullable()->after('package_weight');
        });
    }

    public function down(): void
    {
        Schema::table('ingredients', function (Blueprint $table) {
            $table->dropColumn(['is_packaged', 'package_weight', 'package_unit']);
        });
    }
};
