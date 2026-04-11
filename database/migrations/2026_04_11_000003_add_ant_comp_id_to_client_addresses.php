<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_addresses', function (Blueprint $table) {
            $table->string('ant_comp_id')->nullable()->after('is_default')
                ->comment('Ідентифікатор торгової точки в ANT Logistics для цієї адреси');
        });
    }

    public function down(): void
    {
        Schema::table('client_addresses', function (Blueprint $table) {
            $table->dropColumn('ant_comp_id');
        });
    }
};
