<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Облік енергії за фактом: показники лічильника з фото, тариф з датою початку
 * дії та заправки генератора. Наявні таблиці не зачіпає.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meter_readings', function (Blueprint $t) {
            $t->id();
            $t->date('taken_on')->unique();          // коли знято показник (не коли внесено)
            $t->unsignedInteger('reading');           // кВт·год на лічильнику
            $t->string('photo')->nullable();
            $t->boolean('date_confirmed')->default(true);
            $t->string('comment')->nullable();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->timestamps();
        });

        Schema::create('energy_tariffs', function (Blueprint $t) {
            $t->id();
            $t->date('starts_on')->unique();
            $t->decimal('price', 8, 2);               // грн за кВт·год
            $t->string('comment')->nullable();
            $t->timestamps();
        });

        Schema::create('generator_refuels', function (Blueprint $t) {
            $t->id();
            $t->date('date')->index();
            $t->decimal('liters', 8, 2);
            $t->decimal('price_per_liter', 8, 2);
            $t->decimal('amount', 10, 2);
            $t->string('photo')->nullable();
            $t->string('paid_to')->nullable();
            $t->foreignId('transaction_id')->nullable()->constrained()->nullOnDelete();
            $t->decimal('engine_hours', 8, 1)->nullable();
            $t->string('comment')->nullable();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('generator_refuels');
        Schema::dropIfExists('energy_tariffs');
        Schema::dropIfExists('meter_readings');
    }
};
