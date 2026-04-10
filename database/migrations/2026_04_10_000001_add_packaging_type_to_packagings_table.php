<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('packagings', function (Blueprint $table) {
            $table->string('packaging_type')->nullable()->after('unit');
            $table->decimal('capacity', 8, 2)->nullable()->after('packaging_type');
            $table->string('capacity_unit')->nullable()->after('capacity'); // мл або г
            $table->foreignId('pair_id')->nullable()->after('capacity_unit')
                ->constrained('packagings')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('packagings', function (Blueprint $table) {
            $table->dropForeign(['pair_id']);
            $table->dropColumn(['packaging_type', 'capacity', 'capacity_unit', 'pair_id']);
        });
    }
};
