<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Бриф індивідуального клієнта — суцільним текстом.
     *
     * Клієнт заповнює анкету (вік, вага, ціль, активність, кількість прийомів,
     * що не їсть, що любить), менеджер вставляє її сюди одним шматком. Звідси
     * бриф іде в ШІ, який складає персональне меню.
     *
     * Навмисно не розкладаємо на поля: анкети в клієнтів різні, і будь-яка
     * структура їх обрізатиме. Довідники виключень лишаються як були — вони
     * працюють на виробництво, а бриф працює на підбір страв.
     *
     * Живе на клієнті, а не на замовленні: інакше його довелося б переносити
     * руками в кожне нове замовлення.
     */
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->text('menu_brief')->nullable()->after('production_comment');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn('menu_brief');
        });
    }
};
