<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Крок у треді задачі агента: хід роботи, питання агента, відповідь людини. */
class AiTaskEvent extends Model
{
    public const KINDS = [
        'step' => 'Крок', 'tool' => 'Інструмент', 'question' => 'Питання', 'answer' => 'Відповідь',
        'note' => 'Примітка', 'error' => 'Помилка',
    ];

    protected $guarded = [];

    protected $casts = ['data' => 'array'];

    public function task(): BelongsTo { return $this->belongsTo(AiAgentTask::class, 'ai_agent_task_id'); }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function proposal(): BelongsTo { return $this->belongsTo(AiProposal::class, 'ai_proposal_id'); }
}
