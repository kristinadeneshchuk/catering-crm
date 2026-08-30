<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Звідки прийшла заявка.
 *
 * Мітки лежать одним JSON, а не колонкою на кожен utm: набір параметрів у
 * рекламних систем свій і змінюється, а заводити міграцію щоразу, коли
 * маркетолог додав `utm_content`, — марна робота.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->json('campaign')->nullable()->after('context');
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropColumn('campaign');
        });
    }
};
