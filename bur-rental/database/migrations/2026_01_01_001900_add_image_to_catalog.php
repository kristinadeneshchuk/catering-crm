<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 | Шлях до фото на диску public. Порожньо — вітрина показує плейсхолдер
 | із тією самою пропорцією, тож сторінка не стрибає.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['products', 'categories'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->string('image')->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (['products', 'categories'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropColumn('image');
            });
        }
    }
};
