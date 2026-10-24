<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Тред задачі: хід роботи агента людською мовою, його питання й відповіді людей.
 * Технічні деталі виклику інструменту — у data, в інтерфейсі згорнуті.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_task_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('ai_agent_task_id')->constrained('ai_agent_tasks')->cascadeOnDelete();
            $t->string('kind', 16);                        // step / tool / question / answer / note / error
            $t->text('text')->nullable();
            $t->json('data')->nullable();
            $t->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('ai_proposal_id')->nullable()->constrained('ai_proposals')->nullOnDelete();
            $t->timestamps();
            $t->index(['ai_agent_task_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_task_events');
    }
};
