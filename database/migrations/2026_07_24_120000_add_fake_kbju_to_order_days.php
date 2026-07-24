<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_days', function (Blueprint $table) {
            // Фейкове КБЖУ дня — показується ТІЛЬКИ у меню клієнта по QR.
            // На виробництво, пакування та закупки не впливає (ці поля там не читаються).
            // NULL = показуємо реальне пораховане КБЖУ.
            $table->unsignedSmallInteger('fake_kcal')->nullable()->after('extra_delivery_fee');
            $table->decimal('fake_prot', 6, 1)->nullable()->after('fake_kcal');
            $table->decimal('fake_fat', 6, 1)->nullable()->after('fake_prot');
            $table->decimal('fake_carb', 6, 1)->nullable()->after('fake_fat');
        });
    }

    public function down(): void
    {
        Schema::table('order_days', function (Blueprint $table) {
            $table->dropColumn(['fake_kcal', 'fake_prot', 'fake_fat', 'fake_carb']);
        });
    }
};
