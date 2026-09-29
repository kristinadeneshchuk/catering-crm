<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 | Як рахується ціна зони доставки:
 |   fixed — ціна зони і є сумою (плюс доплати за вагу з RentalPricing);
 |   quote — суму називає менеджер при підтвердженні (область, кілометраж);
 |   info  — не зона, а правило для таблиці на сайті («важку техніку від
 |           7 днів веземо безкоштовно»). У формі бронювання не вибирається:
 |           інакше будь-який кошик отримував безкоштовну доставку.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('delivery_zones', function (Blueprint $table) {
            $table->string('price_mode', 10)->default('fixed')->after('price');
        });
    }

    public function down(): void
    {
        Schema::table('delivery_zones', function (Blueprint $table) {
            $table->dropColumn('price_mode');
        });
    }
};
