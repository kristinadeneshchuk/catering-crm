<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Оновлюємо структуру для гнучкого ціноутворення.
     */
    public function up(): void
    {
        // 1. Видаляємо поле 'days' з тарифів, бо тепер це просто назва категорії
        if (Schema::hasColumn('tariffs', 'days')) {
            Schema::table('tariffs', function (Blueprint $table) {
                $table->dropColumn('days');
            });
        }

        // 2. Додаємо 'duration' (кількість днів) у замовлення
        Schema::table('orders', function (Blueprint $table) {
            if (!Schema::hasColumn('orders', 'duration')) {
                $table->integer('duration')
                    ->after('calories')
                    ->default(1)
                    ->comment('Кількість днів харчування');
            }
        });
    }

    /**
     * Reverse the migrations.
     * Відкат змін до попереднього стану.
     */
    public function down(): void
    {
        // Повертаємо колонку 'days' у тарифи
        Schema::table('tariffs', function (Blueprint $table) {
            $table->integer('days')->default(1)->after('name');
        });

        // Видаляємо 'duration' із замовлень
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('duration');
        });
    }
};