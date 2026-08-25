<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Відмітка «нагадування про повернення надіслано».
 *
 * Без неї крон, який спрацював двічі (перезапуск, здвоєний виклик, ручний
 * прогін), пришле клієнту дві однакові SMS — за наші ж гроші і з виглядом
 * несправного сайту.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->timestamp('return_reminded_at')->nullable()->after('comment');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn('return_reminded_at');
        });
    }
};
