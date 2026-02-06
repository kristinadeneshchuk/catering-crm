<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
public function up(): void
{
    Schema::table('tariffs', function (Blueprint $table) {
        // Додаємо кількість днів та зовнішній ключ
        $table->integer('days')->default(1)->after('name');
        $table->foreignId('calorie_range_id')->nullable()->constrained()->after('days');
    });
}

public function down(): void
{
    Schema::table('tariffs', function (Blueprint $table) {
        $table->dropForeign(['calorie_range_id']);
        $table->dropColumn(['days', 'calorie_range_id']);
    });
}
};
