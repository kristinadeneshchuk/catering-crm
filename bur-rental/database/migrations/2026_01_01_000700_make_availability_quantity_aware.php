<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Наявність по екземплярах, а не по моделі.
 *
 * Було: один рядок = «модель у філії зайнята в цей день», з унікальним індексом.
 * Через це три перфоратори на складі поводилися як один: перша ж бронь робила
 * позицію зайнятою для всіх.
 *
 * Стало: рядок = скільки екземплярів зайнято, з посиланням на бронь. Модель
 * вважається зайнятою, лише коли зайнятих екземплярів не менше, ніж на складі.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('unavailable_dates', function (Blueprint $table) {
            $table->foreignId('booking_id')->nullable()->after('branch_id')
                ->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('qty')->default(1)->after('reason');
        });

        // Унікальність (товар+філія+дата) більше не діє: на одну дату може бути
        // кілька рядків — різні броні на різні екземпляри.
        Schema::table('unavailable_dates', function (Blueprint $table) {
            $table->dropUnique('unavailable_dates_product_id_branch_id_date_unique');
            $table->index(['product_id', 'branch_id', 'date'], 'unavailable_dates_lookup');
        });
    }

    public function down(): void
    {
        Schema::table('unavailable_dates', function (Blueprint $table) {
            $table->dropIndex('unavailable_dates_lookup');
            $table->dropConstrainedForeignId('booking_id');
            $table->dropColumn('qty');
            $table->unique(['product_id', 'branch_id', 'date']);
        });
    }
};
