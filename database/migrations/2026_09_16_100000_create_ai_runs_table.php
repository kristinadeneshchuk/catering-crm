<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Журнал звернень до ШІ: що просили, скільки коштувало, що відповів.
     * Без нього не видно ні витрат, ні того, де ШІ помиляється.
     */
    public function up(): void
    {
        Schema::create('ai_runs', function (Blueprint $table) {
            $table->id();
            $table->string('purpose', 32);              // invoice | courier_report | overuse | …
            $table->string('model', 48);
            $table->string('status', 16)->default('ok'); // ok | failed | invalid
            $table->nullableMorphs('subject');           // документ-чернетка, звіт тощо
            $table->json('input_meta')->nullable();      // без персональних даних: скільки фото, який чат
            $table->json('output')->nullable();
            $table->text('error')->nullable();
            $table->unsignedInteger('input_tokens')->nullable();
            $table->unsignedInteger('output_tokens')->nullable();
            $table->unsignedInteger('cache_read_tokens')->nullable();
            $table->decimal('cost_usd', 8, 4)->nullable();
            $table->unsignedInteger('latency_ms')->nullable();
            $table->timestamps();

            $table->index(['purpose', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_runs');
    }
};
