<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Привʼязка компанії Lunch Hub до клієнта CRM. Живе окремо від знімків дня:
 * знімок перезаписується з кожним надсиланням, а привʼязка має пережити його.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lunch_company_links', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id')->unique();   // id компанії в Lunch Hub
            $t->string('company_name')->nullable();
            $t->foreignId('client_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lunch_company_links');
    }
};
