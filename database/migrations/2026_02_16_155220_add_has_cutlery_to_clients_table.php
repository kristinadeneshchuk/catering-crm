<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
public function up(): void
{
    Schema::table('clients', function (Blueprint $table) {
        // Додаємо поле "Чи додавати прибори"
        $table->boolean('has_cutlery')->default(true)->after('manager_comment');
    });
}

public function down(): void
{
    Schema::table('clients', function (Blueprint $table) {
        $table->dropColumn('has_cutlery');
    });
}
};
