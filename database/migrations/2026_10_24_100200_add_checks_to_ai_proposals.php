<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Результати кодових перевірок пропозиції (MenuGuard) і перехресної перевірки
 * іншим агентом. Нова версія після «Правки» посилається на попередню.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_proposals', function (Blueprint $t) {
            $t->json('checks')->nullable()->after('payload');        // [{code, level: ok|warn|error, text}]
            $t->json('cross_check')->nullable()->after('checks');    // {status: ok|conflict, notes, by_agent}
            $t->unsignedSmallInteger('version')->default(1)->after('cross_check');
            $t->foreignId('supersedes_id')->nullable()->after('version')->constrained('ai_proposals')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('ai_proposals', function (Blueprint $t) {
            $t->dropConstrainedForeignId('supersedes_id');
            $t->dropColumn(['checks', 'cross_check', 'version']);
        });
    }
};
