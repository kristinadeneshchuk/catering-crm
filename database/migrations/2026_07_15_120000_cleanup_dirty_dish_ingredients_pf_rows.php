<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Одноразова чистка «брудних» рядків dish_ingredients.
 *
 * Рядок вважається брудним, коли type='pf', але водночас заповнене
 * ingredient_id (легасі від тих часів, коли на цьому рядку стояв
 * сирий продукт до переведення на НФ). PackagingList дивиться на
 * ingredient_id першим і рендерить старий продукт, а ProductionReport
 * йде за type=pf — через це фасувальний і виробничий листи показують
 * різні інгредієнти на одну й ту саму страву.
 *
 * Правило "нетто = вага готового ПФ, що йде в порцію" підтверджене
 * технологом (сесія 2026-07-15), тому net_weight_g залишається як є —
 * чистимо тільки застаріле посилання на сирий продукт.
 *
 * DOWN спеціально не відновлює стерті ingredient_id: втрачаємо тільки
 * стале посилання, яке відображенню не потрібне. Якщо колись знадобиться
 * знову зв'язати рядок із сирим продуктом — це роблять руками в тех-картці.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('dish_ingredients')
            ->where('type', 'pf')
            ->whereNotNull('ingredient_id')
            ->whereNotNull('child_dish_id')
            ->update(['ingredient_id' => null]);
    }

    public function down(): void
    {
        // Свідомо no-op: див. коментар вище.
    }
};
