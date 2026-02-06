<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
public function up(): void
    {
        Schema::table('ingredients', function (Blueprint $table) {
            // Змінюємо тип на decimal з 3 знаками після коми (напр. 1.500)
            $table->decimal('stock', 10, 3)->change(); 
        });
    }

    public function down(): void
    {
        Schema::table('ingredients', function (Blueprint $table) {
            $table->integer('stock')->change();
        });
    }
};
