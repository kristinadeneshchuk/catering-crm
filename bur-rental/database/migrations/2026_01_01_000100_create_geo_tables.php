<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Географія. Обране місто впливає на телефони, філії та доставку на всіх
 * сторінках, тому воно — окрема сутність, а не рядок у конфізі.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cities', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('name');                 // Київ
            $table->string('name_locative');        // у Києві
            $table->string('phone');
            $table->string('delivery_note')->nullable();
            $table->text('intro')->nullable();
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
        });

        Schema::create('districts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('city_id')->constrained()->cascadeOnDelete();
            $table->string('slug');
            $table->string('name');
            $table->text('intro')->nullable();
            $table->timestamps();
            $table->unique(['city_id', 'slug']);
        });

        Schema::create('branches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('city_id')->constrained()->cascadeOnDelete();
            $table->foreignId('district_id')->nullable()->constrained()->nullOnDelete();
            $table->string('slug');
            $table->string('name');
            $table->string('address');
            $table->string('hours')->default('8:00–20:00 щодня');
            $table->string('last_intake')->nullable();   // час останнього прийому техніки
            $table->string('phone')->nullable();
            $table->string('manager')->nullable();
            $table->string('directions')->nullable();    // метро / авто / парковка
            $table->decimal('distance_km', 5, 1)->nullable();
            $table->decimal('lat', 9, 6)->nullable();
            $table->decimal('lng', 9, 6)->nullable();
            $table->decimal('rating', 2, 1)->nullable();
            $table->unsignedSmallInteger('reviews_count')->default(0);
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
            $table->unique(['city_id', 'slug']);
        });

        Schema::create('delivery_zones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('city_id')->constrained()->cascadeOnDelete();
            $table->string('slug');
            $table->string('name');
            $table->unsignedInteger('price');
            $table->string('eta');                 // час у дорозі
            $table->string('note')->nullable();
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
            $table->unique(['city_id', 'slug']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_zones');
        Schema::dropIfExists('branches');
        Schema::dropIfExists('districts');
        Schema::dropIfExists('cities');
    }
};
