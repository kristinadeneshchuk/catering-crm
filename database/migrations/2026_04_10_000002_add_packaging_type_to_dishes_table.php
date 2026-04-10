<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dishes', function (Blueprint $table) {
            $table->string('packaging_type')->nullable()->after('base_weight_g'); // бокс або пляшка
        });
    }

    public function down(): void
    {
        Schema::table('dishes', function (Blueprint $table) {
            $table->dropColumn('packaging_type');
        });
    }
};
