<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Пропозиція агента, яку погоджує людина; після погодження CRM виконує її сама. */
class AiProposal extends Model
{
    protected $guarded = [];

    protected $casts = [
        'payload'           => 'array',
        'apply_result'      => 'array',
        'telegram_messages' => 'array',
        'reviewed_at'       => 'datetime',
        'applied_at'        => 'datetime',
    ];

    public const TYPES = [
        'individual_week_menu' => 'Індивідуальне меню',
        'client_message'       => 'Повідомлення клієнту',
        'order_replacements'   => 'Заміни в стравах',
    ];

    public const STATUSES = [
        'pending'           => 'Чекає погодження',
        'changes_requested' => 'Правки',
        'rejected'          => 'Відхилено',
        'applied'           => 'Виконано',
        'failed'            => 'Помилка виконання',
    ];

    public function agent(): BelongsTo { return $this->belongsTo(AiAgent::class, 'ai_agent_id'); }
    public function task(): BelongsTo { return $this->belongsTo(AiAgentTask::class, 'ai_agent_task_id'); }
    public function client(): BelongsTo { return $this->belongsTo(Client::class); }
    public function order(): BelongsTo { return $this->belongsTo(Order::class); }
    public function reviewer(): BelongsTo { return $this->belongsTo(User::class, 'reviewed_by'); }

    public function isPending(): bool { return $this->status === 'pending'; }
}
