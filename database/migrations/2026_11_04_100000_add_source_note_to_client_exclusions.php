<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Звідки взялось виключення: manual (менеджер), profile_group (розгорнуто з групи
 * «не можна» у профілі харчування), inbox (з переписки). Примітка — назва групи
 * або цитата клієнта. Старі рядки вважаються ручними.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['client_ingredient_exclusion', 'client_dish_exclusion'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->string('source', 32)->default('manual');
                $t->string('note', 255)->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (['client_ingredient_exclusion', 'client_dish_exclusion'] as $table) {
            Schema::table($table, fn (Blueprint $t) => $t->dropColumn(['source', 'note']));
        }
    }
};
