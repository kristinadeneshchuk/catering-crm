<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Задачі агентам ставить менеджер з картки клієнта/замовлення (docs/tz-ai-agents-in-crm.md §3).
 * Тому задача знає клієнта, замовлення, період, шаблон і скільки на неї витрачено.
 * Статус тепер ще й waiting_human — агент спитав і чекає відповіді.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_agent_tasks', function (Blueprint $t) {
            $t->foreignId('client_id')->nullable()->after('ai_agent_id')->constrained('clients')->nullOnDelete();
            $t->foreignId('order_id')->nullable()->after('client_id')->constrained('orders')->nullOnDelete();
            $t->date('period_from')->nullable()->after('order_id');
            $t->date('period_to')->nullable()->after('period_from');
            $t->string('template', 32)->nullable()->after('period_to');   // menu_period / replacements / chat_check / free
            $t->foreignId('parent_task_id')->nullable()->after('template')->constrained('ai_agent_tasks')->nullOnDelete();
            $t->unsignedSmallInteger('steps')->default(0)->after('result');  // скільки кроків моделі
            $t->decimal('cost_usd', 8, 4)->default(0)->after('steps');
            $t->text('error')->nullable()->after('cost_usd');
            $t->index(['client_id', 'status']);
            $t->index(['order_id', 'status']);
        });
    }

    public function down(): void
    {
        // MySQL: спершу зовнішні ключі (складені індекси їх підпирають), потім індекси й колонки.
        Schema::table('ai_agent_tasks', function (Blueprint $t) {
            $t->dropForeign(['client_id']);
            $t->dropForeign(['order_id']);
            $t->dropForeign(['parent_task_id']);
        });
        Schema::table('ai_agent_tasks', function (Blueprint $t) {
            $t->dropIndex(['client_id', 'status']);
            $t->dropIndex(['order_id', 'status']);
            $t->dropColumn(['client_id', 'order_id', 'parent_task_id', 'period_from', 'period_to', 'template', 'steps', 'cost_usd', 'error']);
        });
    }
};
