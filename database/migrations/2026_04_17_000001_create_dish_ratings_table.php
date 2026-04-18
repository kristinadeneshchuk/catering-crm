<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dish_ratings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('dish_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->unsignedTinyInteger('stars'); // 1–5
            $table->text('comment')->nullable();
            $table->timestamps();

            // Один відгук на одну страву в один день в рамках одного замовлення
            $table->unique(['order_id', 'dish_id', 'date']);
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->boolean('reward_unlocked')->default(false)->after('menu_token');
            $table->boolean('reward_given')->default(false)->after('reward_unlocked');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dish_ratings');

        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['reward_unlocked', 'reward_given']);
        });
    }
};
