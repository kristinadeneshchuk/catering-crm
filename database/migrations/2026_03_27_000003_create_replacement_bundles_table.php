<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('replacement_bundles', function (Blueprint $table) {
            $table->id();
            $table->string('name'); // напр. "Безлактозний", "Безглютеновий"
            $table->text('description')->nullable();
            $table->timestamps();
        });

        Schema::create('replacement_bundle_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('bundle_id');
            $table->unsignedBigInteger('original_ingredient_id');
            $table->unsignedBigInteger('replacement_ingredient_id');
            $table->timestamps();

            $table->foreign('bundle_id')->references('id')->on('replacement_bundles')->cascadeOnDelete();
            $table->foreign('original_ingredient_id')->references('id')->on('ingredients')->cascadeOnDelete();
            $table->foreign('replacement_ingredient_id')->references('id')->on('ingredients')->cascadeOnDelete();

            $table->unique(['bundle_id', 'original_ingredient_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('replacement_bundle_items');
        Schema::dropIfExists('replacement_bundles');
    }
};
