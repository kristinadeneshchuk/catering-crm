<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('ingredients', function (Blueprint $table) {
            $table->string('group')->nullable(); // Фрукти, Овочі тощо
            $table->string('photo')->nullable();
});
    }

    public function down(): void
    {
        Schema::table('ingredients', function (Blueprint $table) {
            // Видаляємо додані колонки при відкаті
            $table->dropColumn(['group', 'photo']);
        });
    }
};
