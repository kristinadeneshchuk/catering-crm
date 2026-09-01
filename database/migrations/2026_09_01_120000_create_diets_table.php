<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Довідник лікувальних дієт (столи за Певзнером) + прив'язка до клієнта
 * + місце для інструкції кухні під конкретну страву конкретного клієнта.
 *
 * Дані наповнюються чернеткою з відкритих джерел і мають бути вичитані
 * технологом — для цього поле is_reviewed. Поки воно false, дієта
 * позначається в інтерфейсі як «не затверджена».
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('diets')) {
            Schema::create('diets', function (Blueprint $table) {
                $table->id();

                // «1», «1а», «5» — рядок, бо є підваріанти з літерами.
                $table->string('number', 8);
                $table->string('name');

                $table->text('indications')->nullable();      // при чому призначають
                $table->text('allowed')->nullable();          // що можна
                $table->text('forbidden')->nullable();        // що не можна
                $table->text('cooking_methods')->nullable();  // спосіб обробки
                $table->text('kitchen_note')->nullable();     // інструкція кухні (загальна для дієти)
                $table->text('reheating_note')->nullable();   // що бачить клієнт у меню за QR

                $table->string('temperature_note')->nullable();
                $table->string('meals_per_day', 32)->nullable();
                $table->string('salt_limit', 64)->nullable();
                $table->string('fluid_limit', 64)->nullable();

                $table->text('sources')->nullable();          // звідки взяті дані
                $table->text('review_notes')->nullable();     // де джерела розходяться

                $table->boolean('is_reviewed')->default(false);
                $table->boolean('is_active')->default(true);
                $table->unsignedSmallInteger('sort_order')->default(0);

                $table->timestamps();

                $table->unique('number');
            });
        }

        if (! Schema::hasColumn('clients', 'diet_id')) {
            Schema::table('clients', function (Blueprint $table) {
                $table->foreignId('diet_id')->nullable()->after('allergies')
                    ->constrained('diets')->nullOnDelete();
            });
        }

        // Інструкція приготування саме цієї страви саме цьому клієнту на цей день.
        // Загальні правила дієти живуть у diets.kitchen_note, а тут — уточнення,
        // яке пише ШІ під час підбору (напр. «картоплю відварити, не запікати»).
        if (! Schema::hasColumn('order_day_dishes', 'cooking_note')) {
            Schema::table('order_day_dishes', function (Blueprint $table) {
                $table->text('cooking_note')->nullable()->after('weight_grams');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('order_day_dishes', 'cooking_note')) {
            Schema::table('order_day_dishes', function (Blueprint $table) {
                $table->dropColumn('cooking_note');
            });
        }

        if (Schema::hasColumn('clients', 'diet_id')) {
            Schema::table('clients', function (Blueprint $table) {
                $table->dropConstrainedForeignId('diet_id');
            });
        }

        Schema::dropIfExists('diets');
    }
};
