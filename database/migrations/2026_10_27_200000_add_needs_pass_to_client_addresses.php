<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Адреса, куди курʼєра без перепустки не пустять (ЖК з охороною, бізнес-центр).
 * Менеджер ставить галочку — і позначка їде в ANT, логістику та на пакет.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_addresses', function (Blueprint $table) {
            $table->boolean('needs_pass')->default(false)->after('delivery_comment');
        });
    }

    public function down(): void
    {
        Schema::table('client_addresses', function (Blueprint $table) {
            $table->dropColumn('needs_pass');
        });
    }
};
