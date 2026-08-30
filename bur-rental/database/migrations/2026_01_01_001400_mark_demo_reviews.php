<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Позначка «це демонстраційний відгук».
 *
 * Відгуки з сидів потрібні, щоб показувати проєкт замовнику, і водночас
 * абсолютно неприпустимі на бойовому сайті: вигаданий відгук — це і обман
 * клієнта, і порушення правил Google із ризиком санкцій на весь домен.
 * Прапорець дозволяє тримати їх у базі й не показувати назовні.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reviews', function (Blueprint $table) {
            $table->boolean('demo')->default(false)->after('source');
        });
    }

    public function down(): void
    {
        Schema::table('reviews', function (Blueprint $table) {
            $table->dropColumn('demo');
        });
    }
};
