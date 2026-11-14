<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Знімок останньої відправки зміни в ANT: які дні доставки туди пішли й коли.
 * За ним видно замовлення, додані чи зняті вже після відправки.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ant_pushes', function (Blueprint $t) {
            $t->id();
            $t->date('delivery_date');
            $t->string('shift', 10);              // morning | evening | all
            $t->dateTime('pushed_at');
            $t->json('points');                   // [{day_id, order_id, client_id, name, shift}]
            $t->foreignId('user_id')->nullable();
            $t->timestamps();
            $t->unique(['delivery_date', 'shift']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ant_pushes');
    }
};
