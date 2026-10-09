<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Автозвірка з monobank, етап 1: токен у рахунку (зашифрований) і
 * операції банку окремою таблицею. Наявні оплати не чіпаємо.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $t) {
            $t->text('mono_token')->nullable();              // encrypted cast
            $t->string('mono_account_id', 64)->nullable();   // id рахунку ФОП у client-info
            $t->string('mono_iban', 34)->nullable();
            $t->timestamp('mono_synced_at')->nullable();
        });

        Schema::create('bank_operations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('account_id')->constrained('accounts')->cascadeOnDelete();
            $t->string('bank_id', 64);                       // id операції в банку
            $t->dateTime('operated_at');
            $t->decimal('amount', 12, 2);                    // + надходження, − витрата
            $t->decimal('balance_after', 12, 2)->nullable();
            $t->text('description')->nullable();
            $t->text('comment')->nullable();
            $t->string('counter_name')->nullable();
            $t->string('counter_iban', 34)->nullable();
            $t->string('counter_edrpou', 16)->nullable();
            $t->unsignedSmallInteger('mcc')->nullable();
            $t->json('raw')->nullable();
            $t->timestamps();

            $t->unique(['account_id', 'bank_id']);
            $t->index(['account_id', 'operated_at']);
            $t->index('operated_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_operations');
        Schema::table('accounts', function (Blueprint $t) {
            $t->dropColumn(['mono_token', 'mono_account_id', 'mono_iban', 'mono_synced_at']);
        });
    }
};
