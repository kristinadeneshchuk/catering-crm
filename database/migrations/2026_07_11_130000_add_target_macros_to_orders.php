<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Індивідуальні цілі КБЖУ на замовлення.
 *
 * Ккал уже жила в orders.calories. Додаємо решту 3 макро як опціональні —
 * заповнюються тоді, коли клієнт хоче свій профіль (напр. родина з дитиною
 * хоче менше жирів, спортсмен хоче більше білка). Якщо всі три NULL —
 * поведінка як раніше (масштабування тільки під калораж).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->unsignedSmallInteger('target_protein_g')->nullable()->after('calories');
            $table->unsignedSmallInteger('target_fats_g')->nullable()->after('target_protein_g');
            $table->unsignedSmallInteger('target_carbs_g')->nullable()->after('target_fats_g');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['target_protein_g', 'target_fats_g', 'target_carbs_g']);
        });
    }
};
