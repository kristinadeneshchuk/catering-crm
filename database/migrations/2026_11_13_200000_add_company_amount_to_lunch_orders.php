<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Часткова оплата обідів: частину платить компанія, частину — самі
 * співробітники (особистими рахунками в Lunch Hub). Боргом клієнта-компанії в
 * CRM стає лише company_amount; якщо поле порожнє — уся сума дня (amount).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lunch_orders', function (Blueprint $t) {
            $t->decimal('company_amount', 10, 2)->nullable()->after('amount');
            $t->decimal('employees_amount', 10, 2)->nullable()->after('company_amount');
        });
    }

    public function down(): void
    {
        Schema::table('lunch_orders', function (Blueprint $t) {
            $t->dropColumn(['company_amount', 'employees_amount']);
        });
    }
};
