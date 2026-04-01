<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meal_types', function (Blueprint $table) {
            $table->string('color', 7)->default('#94a3b8')->after('energy_percent');
            $table->string('short_letter', 4)->default('?')->after('color');
        });

        // Дефолтні кольори та літери для існуючих записів по sort_order
        $defaults = [
            1 => ['color' => '#14b8a6', 'short_letter' => 'С'],
            2 => ['color' => '#a3e635', 'short_letter' => 'П'],
            3 => ['color' => '#fb923c', 'short_letter' => 'О'],
            4 => ['color' => '#f472b6', 'short_letter' => 'П'],
            5 => ['color' => '#38bdf8', 'short_letter' => 'В'],
            6 => ['color' => '#c084fc', 'short_letter' => 'Д'],
        ];

        foreach ($defaults as $sortOrder => $values) {
            DB::table('meal_types')->where('sort_order', $sortOrder)->update($values);
        }
    }

    public function down(): void
    {
        Schema::table('meal_types', function (Blueprint $table) {
            $table->dropColumn(['color', 'short_letter']);
        });
    }
};
