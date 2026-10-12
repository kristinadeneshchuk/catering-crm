<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Реквізити отримувача на рахунку (ФОП) — щоб при виставленні рахунку клієнту
 * можна було обрати, на кого платити, а не лише реквізити бренду.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $t) {
            $t->string('recipient_name')->nullable()->after('name');
            $t->string('iban', 34)->nullable()->after('recipient_name');
            $t->string('tax_id', 20)->nullable()->after('iban');
            $t->string('bank_name')->nullable()->after('tax_id');
            $t->string('mfo', 10)->nullable()->after('bank_name');
        });
    }

    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $t) {
            $t->dropColumn(['recipient_name', 'iban', 'tax_id', 'bank_name', 'mfo']);
        });
    }
};
