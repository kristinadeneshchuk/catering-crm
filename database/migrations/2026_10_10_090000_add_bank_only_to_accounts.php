<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Рахунок «лише для банку» (напр. біла картка власниці ФОП): операції
 * видно в «Банк», але менеджерам у виборі рахунку для оплат його немає.
 * mono_account_type — який рахунок брати з токена: fop / white / black.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $t) {
            $t->boolean('bank_only')->default(false);
            $t->string('mono_account_type', 16)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $t) {
            $t->dropColumn(['bank_only', 'mono_account_type']);
        });
    }
};
