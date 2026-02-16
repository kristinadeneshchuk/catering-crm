<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use App\Models\Setting; // Підключаємо модель

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Створюємо налаштування, якщо його ще немає
        Setting::firstOrCreate(
            ['key' => 'menu_cycle_start_date'], // Перевіряємо по ключу
            ['value' => '2025-01-01']           // Значення за замовчуванням
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // При відкаті міграції (php artisan migrate:rollback) видаляємо це налаштування
        Setting::where('key', 'menu_cycle_start_date')->delete();
    }
};