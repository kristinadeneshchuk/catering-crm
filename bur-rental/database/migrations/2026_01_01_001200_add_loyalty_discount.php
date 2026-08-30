<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Знижка постійного клієнта.
 *
 * У броні зберігається і відсоток, і сума: відсоток може змінитися завтра
 * (клієнт дійшов до наступної сходинки, замовник переписав конфіг), а рахунок
 * за вчорашню оренду мусить лишитися тим самим. Порахувати знижку заново
 * через місяць — це отримати іншу цифру, ніж бачив клієнт.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            // Ручна знижка від менеджера. Порожньо = діє сходинка за історією.
            $table->unsignedTinyInteger('discount_percent')->nullable()->after('edrpou');
        });

        Schema::table('bookings', function (Blueprint $table) {
            $table->unsignedTinyInteger('discount_percent')->default(0)->after('deposit_total');
            $table->unsignedInteger('discount_total')->default(0)->after('discount_percent');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn('discount_percent');
        });

        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn(['discount_percent', 'discount_total']);
        });
    }
};
