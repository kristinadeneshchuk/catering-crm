<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_replacement_bundle', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('client_id');
            $table->unsignedBigInteger('replacement_bundle_id');
            $table->timestamps();

            $table->foreign('client_id')->references('id')->on('clients')->cascadeOnDelete();
            $table->foreign('replacement_bundle_id')->references('id')->on('replacement_bundles')->cascadeOnDelete();

            $table->unique(['client_id', 'replacement_bundle_id'], 'client_bundle_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_replacement_bundle');
    }
};
