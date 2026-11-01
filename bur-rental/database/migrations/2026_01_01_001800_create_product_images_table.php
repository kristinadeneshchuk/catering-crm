<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 | Фото товарів. Оригінал лежить як завантажили, поруч — три WebP-розміри
 | (sm 400 / md 800 / lg 1600 px), їх робить App\Services\ProductImages.
 | source_url — звідки фото взялося (сторінка виробника), щоб завжди можна
 | було показати, що фото офіційне, і прибрати його, якщо попросять.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('path');
            $table->string('alt')->nullable();
            $table->unsignedSmallInteger('position')->default(0);
            $table->unsignedSmallInteger('width')->nullable();
            $table->unsignedSmallInteger('height')->nullable();
            $table->string('source_url', 1000)->nullable();
            $table->timestamps();
            $table->index(['product_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_images');
    }
};
