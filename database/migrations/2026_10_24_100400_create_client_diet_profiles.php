<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Профіль харчування індивідуального клієнта — те, що досі жило в теці на Маку
 * (profiles/*.md, count_rules.py, client_memos.yaml). Жорсткі «не можна» — у виключеннях
 * клієнта, тут — режим, «краще ні», «більше», правила-частоти, улюблені страви й пам'ятка кухні.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_diet_profiles', function (Blueprint $t) {
            $t->id();
            $t->foreignId('client_id')->unique()->constrained('clients')->cascadeOnDelete();
            $t->string('mode', 24)->default('cycle_replacements'); // cycle_replacements / hybrid / own_menu_only / vip
            $t->json('excluded_groups')->nullable(); // [slug] — «не можна» цілою групою (кукурудза, бобові…)
            $t->json('avoid')->nullable();          // [{ingredient_id|group_slug|text}] — краще ні
            $t->json('want_more')->nullable();      // [текст] — улюблене, чого більше
            $t->json('rules')->nullable();          // [{kind: max_per_day|max_per_week|only, what, value, text}]
            $t->json('favorite_dish_ids')->nullable();
            $t->text('kitchen_memo')->nullable();   // для кухні: без грошей і без згадок ШІ
            $t->text('notes')->nullable();          // для агента й менеджера
            $t->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_diet_profiles');
    }
};
