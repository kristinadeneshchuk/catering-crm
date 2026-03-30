<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Таблиця планів харчування (наприклад: "Легкий 3 страви", "Стандарт 5 страв")
        Schema::create('meal_plans', function (Blueprint $table) {
            $table->id();
            $table->string('name');                          // Назва плану
            $table->unsignedSmallInteger('min_kcal');        // Від (включно)
            $table->unsignedSmallInteger('max_kcal');        // До (включно)
            $table->timestamps();
        });

        // Зв'язок план ↔ типи прийомів їжі
        Schema::create('meal_plan_meal_type', function (Blueprint $table) {
            $table->foreignId('meal_plan_id')
                ->constrained('meal_plans')
                ->cascadeOnDelete();
            $table->foreignId('meal_type_id')
                ->constrained('meal_types')
                ->cascadeOnDelete();
            $table->primary(['meal_plan_id', 'meal_type_id']);
        });

        // Заповнюємо дефолтними планами на основі поточної логіки ClientResource
        $mealTypes = DB::table('meal_types')->get()->keyBy('sort_order');

        $plans = [
            ['name' => 'Легкий (3 страви)',    'min_kcal' => 0,    'max_kcal' => 1099, 'sort_orders' => [1, 3, 5]],
            ['name' => 'Базовий (4 страви)',   'min_kcal' => 1100, 'max_kcal' => 1299, 'sort_orders' => [1, 2, 3, 5]],
            ['name' => 'Стандарт (5 страв)',   'min_kcal' => 1300, 'max_kcal' => 1599, 'sort_orders' => [1, 2, 3, 4, 5]],
            ['name' => 'Посилений (6 страв)',  'min_kcal' => 1600, 'max_kcal' => 9999, 'sort_orders' => [1, 2, 3, 4, 5, 6]],
        ];

        foreach ($plans as $plan) {
            $planId = DB::table('meal_plans')->insertGetId([
                'name'       => $plan['name'],
                'min_kcal'   => $plan['min_kcal'],
                'max_kcal'   => $plan['max_kcal'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            foreach ($plan['sort_orders'] as $sortOrder) {
                $mealType = $mealTypes->get($sortOrder);
                if ($mealType) {
                    DB::table('meal_plan_meal_type')->insert([
                        'meal_plan_id' => $planId,
                        'meal_type_id' => $mealType->id,
                    ]);
                }
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('meal_plan_meal_type');
        Schema::dropIfExists('meal_plans');
    }
};
