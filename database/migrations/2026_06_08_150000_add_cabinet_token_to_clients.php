<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->string('cabinet_token', 40)->nullable()->unique()->after('password');
        });

        // Бекфіл: персональний токен кабінету для кожного наявного клієнта
        DB::table('clients')->whereNull('cabinet_token')->select('id')->orderBy('id')->each(function ($c) {
            DB::table('clients')->where('id', $c->id)->update(['cabinet_token' => Str::random(32)]);
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn('cabinet_token');
        });
    }
};
