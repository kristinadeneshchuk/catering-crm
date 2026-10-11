<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Пульт ШІ-агентів: агент пропонує → людина погоджує → CRM виконує.
 * Агенти (поки сесії Claude на Маку) ходять сюди по API зі своїм токеном:
 * беруть задачі, кладуть пропозиції й звіти, тримають памʼять.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_agents', function (Blueprint $t) {
            $t->id();
            $t->string('slug', 64)->unique();
            $t->string('name');
            $t->text('role')->nullable();                 // що робить агент
            $t->longText('instructions')->nullable();     // правила власника, які агент читає на старті
            $t->json('allowed_types')->nullable();        // які пропозиції може класти
            $t->string('schedule')->nullable();           // людський опис розкладу
            $t->string('token_hash', 64)->nullable()->unique();
            $t->boolean('is_active')->default(true);
            $t->timestamp('last_seen_at')->nullable();
            $t->timestamps();
        });

        Schema::create('ai_agent_tasks', function (Blueprint $t) {
            $t->id();
            $t->foreignId('ai_agent_id')->constrained('ai_agents')->cascadeOnDelete();
            $t->string('title');
            $t->text('details')->nullable();
            $t->json('payload')->nullable();
            $t->string('status', 16)->default('new');     // new / in_progress / done / failed / cancelled
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('due_at')->nullable();
            $t->timestamp('started_at')->nullable();
            $t->timestamp('finished_at')->nullable();
            $t->text('result')->nullable();
            $t->timestamps();
            $t->index(['ai_agent_id', 'status']);
        });

        Schema::create('ai_proposals', function (Blueprint $t) {
            $t->id();
            $t->foreignId('ai_agent_id')->constrained('ai_agents')->cascadeOnDelete();
            $t->foreignId('ai_agent_task_id')->nullable()->constrained('ai_agent_tasks')->nullOnDelete();
            $t->string('type', 32);                        // individual_week_menu / client_message / …
            $t->string('title');
            $t->text('summary')->nullable();               // що агент пропонує людською мовою
            $t->foreignId('client_id')->nullable()->constrained('clients')->nullOnDelete();
            $t->foreignId('order_id')->nullable()->constrained('orders')->nullOnDelete();
            $t->json('payload');
            $t->string('status', 20)->default('pending'); // pending / changes_requested / rejected / applied / failed
            $t->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('reviewed_at')->nullable();
            $t->text('review_comment')->nullable();
            $t->timestamp('applied_at')->nullable();
            $t->json('apply_result')->nullable();
            $t->json('telegram_messages')->nullable();     // [{chat_id, message_id}] — щоб оновити кнопки
            $t->timestamps();
            $t->index(['status', 'type']);
        });

        Schema::create('ai_agent_reports', function (Blueprint $t) {
            $t->id();
            $t->foreignId('ai_agent_id')->constrained('ai_agents')->cascadeOnDelete();
            $t->foreignId('ai_agent_task_id')->nullable()->constrained('ai_agent_tasks')->nullOnDelete();
            $t->string('title');
            $t->longText('body')->nullable();
            $t->string('level', 8)->default('info');       // info / warn / error
            $t->timestamps();
        });

        Schema::create('ai_agent_memory', function (Blueprint $t) {
            $t->id();
            $t->foreignId('ai_agent_id')->constrained('ai_agents')->cascadeOnDelete();
            $t->string('key');
            $t->json('value')->nullable();
            $t->timestamps();
            $t->unique(['ai_agent_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_agent_memory');
        Schema::dropIfExists('ai_agent_reports');
        Schema::dropIfExists('ai_proposals');
        Schema::dropIfExists('ai_agent_tasks');
        Schema::dropIfExists('ai_agents');
    }
};
