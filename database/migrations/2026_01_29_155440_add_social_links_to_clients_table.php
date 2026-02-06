<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Додаємо поля для соцмереж у таблицю клієнтів
     */
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            // Додаємо нові поля після існуючих контактів
            $table->string('instagram_url')->nullable()->after('phone');
            $table->string('telegram_username')->nullable()->after('instagram_url');
            $table->string('facebook_url')->nullable()->after('telegram_username');
        });
    }

    /**
     * Видаляємо поля при відкаті міграції
     */
    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            // Видаляємо масивом для чистоти коду
            $table->dropColumn(['instagram_url', 'telegram_username', 'facebook_url']);
        });
    }
};