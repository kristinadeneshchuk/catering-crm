<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employee_shifts', function (Blueprint $table) {
            $table->boolean('is_half')->default(false)->after('is_duty');
        });
    }

    public function down(): void
    {
        Schema::table('employee_shifts', function (Blueprint $table) {
            $table->dropColumn('is_half');
        });
    }
};
