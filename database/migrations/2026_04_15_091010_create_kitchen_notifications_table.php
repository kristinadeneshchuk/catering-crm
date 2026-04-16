<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kitchen_notifications', function (Blueprint $table) {
            $table->id();
            $table->enum('type', ['new_client', 'extension'])->default('new_client');
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->string('client_name');
            $table->integer('calories');
            $table->string('schedule_type')->nullable();
            $table->string('project')->nullable();
            $table->boolean('has_exclusions')->default(false);
            $table->integer('duration')->default(1);
            $table->date('start_date');
            $table->string('message');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kitchen_notifications');
    }
};
