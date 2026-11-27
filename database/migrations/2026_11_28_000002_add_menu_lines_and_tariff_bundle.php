<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Лінійки меню («Спорт», «Спорт Про»): шаблон замін можна показати окремою
 * лінійкою на публічному /menu-today і прив'язати до тарифу — замовлення
 * з таким тарифом отримує заміни страв саме.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('replacement_bundles', function (Blueprint $table) {
            $table->boolean('show_on_menu')->default(false)->after('description');
            $table->unsignedSmallInteger('menu_sort')->default(0)->after('show_on_menu');
        });

        Schema::table('tariffs', function (Blueprint $table) {
            $table->unsignedBigInteger('replacement_bundle_id')->nullable();
            $table->foreign('replacement_bundle_id')->references('id')->on('replacement_bundles')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('tariffs', function (Blueprint $table) {
            $table->dropForeign(['replacement_bundle_id']);
            $table->dropColumn('replacement_bundle_id');
        });

        Schema::table('replacement_bundles', function (Blueprint $table) {
            $table->dropColumn(['show_on_menu', 'menu_sort']);
        });
    }
};
