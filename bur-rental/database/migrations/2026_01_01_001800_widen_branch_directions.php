<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 | «Як доїхати» — вільний текст із textarea в адмінці, у сидах є 273 символи.
 | SQLite довжину varchar не перевіряє, тому це вилізло лише на MySQL:
 | db:seed падав з «Data too long for column 'directions'».
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->text('directions')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->string('directions')->nullable()->change();
        });
    }
};
