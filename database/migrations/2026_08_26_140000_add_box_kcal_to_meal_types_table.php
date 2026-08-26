<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Енергія бокса для прийому їжі — окремо для звичайної та великої порції.
     *
     * Це основа другої версії фасування. Зараз енергія прийому задається
     * відсотком від калоражу замовлення, через що у страви стільки ваг,
     * скільки калоражів — до двадцяти. Фасувальна станція від цього страждає.
     *
     * У новій моделі енергія бокса фіксована в кілокалоріях, і у страви
     * рівно дві ваги: під Std і під L.
     *
     * Порожньо = прийом у другій версії не бере участі. Стару логіку це поле
     * не зачіпає взагалі.
     */
    public function up(): void
    {
        Schema::table('meal_types', function (Blueprint $table) {
            $table->unsignedSmallInteger('box_kcal_std')->nullable()->after('energy_percent');
            $table->unsignedSmallInteger('box_kcal_large')->nullable()->after('box_kcal_std');
        });
    }

    public function down(): void
    {
        Schema::table('meal_types', function (Blueprint $table) {
            $table->dropColumn(['box_kcal_std', 'box_kcal_large']);
        });
    }
};
