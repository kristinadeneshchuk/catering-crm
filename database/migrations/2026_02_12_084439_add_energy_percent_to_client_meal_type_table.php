<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_meal_type', function (Blueprint $table) {
            // Додаємо поле для відсотка енергії (калорійності) прийому їжі
            $table->integer('energy_percent')->default(0)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('client_meal_type', function (Blueprint $table) {
            $table->dropColumn('energy_percent');
        });
    }
};