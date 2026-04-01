<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_replacements', function (Blueprint $table) {
            $table->boolean('force_approved')->default(false)->after('comment');
        });
    }

    public function down(): void
    {
        Schema::table('order_replacements', function (Blueprint $table) {
            $table->dropColumn('force_approved');
        });
    }
};
