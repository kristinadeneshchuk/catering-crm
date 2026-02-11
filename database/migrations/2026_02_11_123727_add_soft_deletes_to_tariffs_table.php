<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
public function up(): void
{
    Schema::table('tariffs', function (Blueprint $table) {
        $table->softDeletes(); // Додає колонку deleted_at
    });
}

public function down(): void
{
    Schema::table('tariffs', function (Blueprint $table) {
        $table->dropSoftDeletes();
    });
}
};
