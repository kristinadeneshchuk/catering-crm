<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Мінімальна кількість днів для тарифу.
     *
     * Досі строк був зашитий у назву текстом («Від 5-7 днів», «від 20-21 дня»),
     * і зовнішні системи змушені були парсити назву. Тепер це окреме поле:
     * null = обмеження немає (поточна поведінка для всіх існуючих тарифів).
     */
    public function up(): void
    {
        Schema::table('tariffs', function (Blueprint $table) {
            $table->unsignedSmallInteger('min_days')->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('tariffs', function (Blueprint $table) {
            $table->dropColumn('min_days');
        });
    }
};
