<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Бронювання і заявки на дзвінок.
 *
 * Ціни в позиціях фіксуються на момент броні: тариф може змінитися,
 * а клієнт бачив конкретну цифру і має отримати саме її.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bookings', function (Blueprint $table) {
            $table->id();
            $table->string('number')->unique();          // BUR-26-000123
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status')->default('new');    // new | confirmed | issued | closed | cancelled
            $table->string('client_type')->default('person'); // person | company
            $table->string('name')->nullable();
            $table->string('phone');
            $table->string('email')->nullable();
            $table->string('company')->nullable();
            $table->string('edrpou', 8)->nullable();
            $table->string('fulfilment')->default('self'); // self | delivery
            $table->foreignId('delivery_zone_id')->nullable()->constrained()->nullOnDelete();
            $table->string('address')->nullable();
            $table->string('payment')->default('card');
            $table->string('deposit_way')->default('card-hold');
            $table->date('date_from');
            $table->date('date_to');
            $table->unsignedInteger('rent_total');
            $table->unsignedInteger('extras_total')->default(0);
            $table->unsignedInteger('delivery_total')->default(0);
            $table->unsignedInteger('deposit_total')->default(0);
            $table->text('comment')->nullable();
            $table->timestamps();
        });

        Schema::create('booking_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('extra_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title');                     // знімок назви на момент броні
            $table->unsignedSmallInteger('qty')->default(1);
            $table->unsignedSmallInteger('days')->default(1);
            $table->unsignedInteger('price_per_day')->default(0);
            $table->unsignedInteger('total');
            $table->unsignedInteger('deposit')->default(0);
            $table->timestamps();
        });

        Schema::create('leads', function (Blueprint $table) {
            $table->id();
            $table->string('kind')->default('callback'); // callback | b2b | contact | notify
            $table->string('name')->nullable();
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->string('company')->nullable();
            $table->string('edrpou', 8)->nullable();
            $table->string('context')->nullable();       // з якої сторінки прийшла заявка
            $table->text('message')->nullable();
            $table->string('status')->default('new');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leads');
        Schema::dropIfExists('booking_items');
        Schema::dropIfExists('bookings');
    }
};
