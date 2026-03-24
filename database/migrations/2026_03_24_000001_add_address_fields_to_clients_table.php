<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->string('address_entrance')->nullable()->after('address');
            $table->string('address_apartment')->nullable()->after('address_entrance');
            $table->string('address_floor')->nullable()->after('address_apartment');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn(['address_entrance', 'address_apartment', 'address_floor']);
        });
    }
};
