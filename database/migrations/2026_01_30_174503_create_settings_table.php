<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Створення таблиці для глобальних параметрів бізнесу.
     */
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();   // Назва налаштування (напр. 'menu_cycle_days')
            $table->string('value');           // Значення (напр. '24')
            $table->timestamps();
        });

        // Встановлюємо початкове значення циклу — 24 дні
        DB::table('settings')->insert([
            'key' => 'menu_cycle_days',
            'value' => '24',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Видалення таблиці при відкаті.
     */
    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};