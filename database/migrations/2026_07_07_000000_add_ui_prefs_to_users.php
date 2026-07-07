<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Персональні UI-налаштування користувача (JSON).
 * Зараз використовується для «запам'ятаних» дат на сторінках
 * Зарплати / Логістика / Табель — щоб вибір не скидався на сьогодні.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->json('ui_prefs')->nullable()->after('role');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('ui_prefs');
        });
    }
};
