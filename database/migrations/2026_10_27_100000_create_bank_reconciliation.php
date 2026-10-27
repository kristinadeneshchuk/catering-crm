<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * «Звірка»: кожна операція з банку або розібрана (привʼязана до записів CRM),
 * або «не стосується», або чекає розбору. Правила контрагентів — як у Saldo:
 * один раз сказали «Київенерго — комунальні», далі CRM розбирає сама.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bank_operations', function (Blueprint $t) {
            $t->string('reconcile_status', 16)->nullable()->index(); // null — не розібрано, matched, ignored
            $t->string('reconcile_note')->nullable();
            $t->unsignedBigInteger('reconciled_by')->nullable();
            $t->timestamp('reconciled_at')->nullable();
        });

        Schema::create('bank_operation_transaction', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('bank_operation_id');
            $t->unsignedBigInteger('transaction_id');
            // Запис створено саме зі «Звірки» — при скасуванні розбору його видаляємо.
            $t->boolean('created_by_reconcile')->default(false);
            $t->timestamps();
            $t->unique(['bank_operation_id', 'transaction_id'], 'bot_unique');
            $t->index('transaction_id');
        });

        Schema::create('counterparty_rules', function (Blueprint $t) {
            $t->id();
            $t->string('edrpou', 16)->nullable()->index();
            $t->string('iban', 34)->nullable()->index();
            $t->string('name')->nullable();
            $t->string('direction', 8); // in | out
            $t->string('action', 16);   // expense | income | salary | ignore
            $t->string('category')->nullable();
            $t->unsignedBigInteger('employee_id')->nullable();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('counterparty_rules');
        Schema::dropIfExists('bank_operation_transaction');
        Schema::table('bank_operations', function (Blueprint $t) {
            $t->dropIndex(['reconcile_status']);
            $t->dropColumn(['reconcile_status', 'reconcile_note', 'reconciled_by', 'reconciled_at']);
        });
    }
};
